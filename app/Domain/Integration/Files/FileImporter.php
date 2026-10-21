<?php

namespace App\Domain\Integration\Files;

use App\Domain\Integration\Canonical\CanonicalModel;
use App\Domain\Integration\Models\IntegrationConnector;
use App\Domain\Integration\Models\IntegrationRun;
use App\Domain\Integration\Services\IntegrationInbox;
use App\Domain\Platform\Services\AuditLogger;
use App\Models\User;
use App\Support\Exceptions\BusinessRuleViolation;

/**
 * Imports a file (uploaded, pushed by the source system or pulled over SFTP)
 * into the integration inbox. The same file content is imported once per
 * connector: a second copy returns the first run and stores nothing.
 *
 * Each row's message_id is the value of settings.message_id_field when the
 * file carries one, else "<first 16 hex of the file hash>:<row>". Rows from a
 * corrected file therefore get new message ids and are applied idempotently.
 *
 * An unreadable file is still recorded as a run with its error, so failed
 * transfers are visible in the run history and health view.
 */
class FileImporter
{
    public function __construct(
        private readonly FileParser $parser,
        private readonly IntegrationInbox $inbox,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array{run: IntegrationRun, duplicate_file: bool, acknowledgements: list<array>} */
    public function import(IntegrationConnector $connector, string $content, string $fileName, string $entity, ?string $format, string $source, ?User $user = null): array
    {
        if (! $connector->acceptsMessages()) {
            throw new BusinessRuleViolation('connector_inactive', 'Activate the connector before importing files.', 409);
        }
        if (! in_array($entity, CanonicalModel::ingestible(), true)) {
            throw new BusinessRuleViolation('entity_not_ingestible', "{$entity} records are not applied by the gateway yet.", 422, ['ingestible' => CanonicalModel::ingestible()]);
        }

        $sha = hash('sha256', $content);
        $previous = IntegrationRun::query()->where('connector_id', $connector->id)->where('file_sha256', $sha)->whereNull('error_code')->first();
        if ($previous !== null) {
            return ['run' => $previous, 'duplicate_file' => true, 'acknowledgements' => []];
        }

        $format ??= FileParser::formatFromName($fileName) ?? throw new BusinessRuleViolation('file_format_unknown', 'State the file format (csv, json or xml).', 422);
        $settings = $connector->settings ?? [];

        try {
            $records = $this->parser->parse($content, $format, $settings);
        } catch (BusinessRuleViolation $e) {
            $run = IntegrationRun::query()->create(['connector_id' => $connector->id, 'source' => $source, 'direction' => 'inbound', 'dataset' => $entity,
                'file_name' => $fileName, 'file_sha256' => $sha, 'created_by' => $user?->id, 'started_at' => now(),
                'error_code' => $e->errorCode, 'error_message' => $e->getMessage()]);
            $connector->forceFill(['last_failure_at' => now(), 'last_error' => mb_strimwidth("File {$fileName}: {$e->getMessage()}", 0, 255)])->saveQuietly();

            throw new BusinessRuleViolation($e->errorCode, $e->getMessage(), $e->status, $e->details + ['run_id' => $run->id]);
        }

        $idField = $settings['message_id_field'] ?? null;
        $prefix = substr($sha, 0, 16);
        $envelopes = [];
        foreach ($records as $i => $record) {
            $own = $idField !== null ? data_get($record, $idField) : null;
            $envelopes[] = ['message_id' => is_scalar($own) && (string) $own !== '' ? (string) $own : "{$prefix}:".($i + 1), 'entity_type' => $entity, 'record' => $record];
        }

        $result = $this->inbox->receive($connector, $envelopes, $source, $user, $fileName, $sha);
        $result['run']->forceFill(['dataset' => $entity])->save();
        $connector->forceFill(['last_error' => null])->saveQuietly(); // a readable file clears an earlier file error
        $this->audit->record('integration.file.imported', $result['run'], new: ['file_name' => $fileName, 'file_sha256' => $sha, 'entity_type' => $entity,
            'records' => count($records), 'source' => $source], metadata: ['connector_id' => $connector->id]);

        return ['run' => $result['run'], 'duplicate_file' => false, 'acknowledgements' => $result['acknowledgements']];
    }
}

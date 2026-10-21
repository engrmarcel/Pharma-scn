<?php

namespace App\Domain\Integration\Services;

use App\Domain\Integration\Canonical\CanonicalModel;
use App\Domain\Integration\Jobs\ProcessIntegrationMessage;
use App\Domain\Integration\Models\IntegrationConnector;
use App\Domain\Integration\Models\IntegrationMessage;
use App\Domain\Integration\Models\IntegrationRun;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Intake into the durable integration inbox (spec §15.3).
 *
 * Every record is stored before anything is acknowledged, and the
 * acknowledgement says only what is true at that moment: "accepted" means
 * stored and queued for processing, never "processed". The source learns the
 * business outcome from the message status endpoint.
 *
 * Idempotency: message_id is unique per connector. Resending the same record
 * under the same message_id returns the stored status ("duplicate") and is
 * never applied twice; a different record under a used message_id is refused.
 */
class IntegrationInbox
{
    private const MESSAGE_ID = '/^[A-Za-z0-9][A-Za-z0-9._:\/-]{0,127}$/';

    /**
     * @param  list<mixed>  $records  each {message_id, entity_type, schema_version?, record}
     * @return array{run: IntegrationRun, acknowledgements: list<array>}
     */
    public function receive(IntegrationConnector $connector, array $records, string $source, ?User $user = null, ?string $fileName = null, ?string $fileSha256 = null): array
    {
        [$run, $acks, $created] = DB::transaction(function () use ($connector, $records, $source, $user, $fileName, $fileSha256) {
            $run = IntegrationRun::query()->create(['connector_id' => $connector->id, 'source' => $source, 'file_name' => $fileName,
                'file_sha256' => $fileSha256, 'created_by' => $user?->id, 'started_at' => now()]);
            $acks = [];
            $created = [];
            $counts = ['received_count' => 0, 'duplicate_count' => 0, 'rejected_count' => 0];

            foreach (array_values($records) as $i => $envelope) {
                [$ack, $message] = $this->one($connector, $run, $envelope);
                $acks[] = ['index' => $i] + $ack;
                $counts[match ($ack['status']) {
                    'accepted' => 'received_count', 'rejected' => 'rejected_count', default => 'duplicate_count'
                }]++;
                if ($message !== null) {
                    $created[] = $message->id;
                }
            }

            $run->forceFill($counts)->save();
            $connector->forceFill(['last_message_at' => now()])->saveQuietly();

            return [$run, $acks, $created];
        });

        // Only after the inbox write has committed. A paused connector keeps its messages until resumed.
        if ($connector->status === 'active') {
            foreach ($created as $id) {
                ProcessIntegrationMessage::dispatch($id);
            }
        }

        return ['run' => $run, 'acknowledgements' => $acks];
    }

    /** @return array{0: array, 1: ?IntegrationMessage} */
    private function one(IntegrationConnector $connector, IntegrationRun $run, mixed $envelope): array
    {
        $messageId = is_array($envelope) && is_string($envelope['message_id'] ?? null) ? $envelope['message_id'] : null;
        $entity = is_array($envelope) && is_string($envelope['entity_type'] ?? null) ? $envelope['entity_type'] : null;
        $record = is_array($envelope) ? ($envelope['record'] ?? null) : null;
        $reject = fn (string $code, string $message, array $details = []) => [array_filter([
            'message_id' => $messageId, 'status' => 'rejected', 'error' => array_filter(['code' => $code, 'message' => $message, 'details' => $details ?: null]),
        ]), null];

        if ($messageId === null || ! preg_match(self::MESSAGE_ID, $messageId)) {
            return $reject('message_id_invalid', 'message_id is required: 1–128 characters of letters, digits and . _ : / -, unique per record.');
        }
        if ($entity === null || ! CanonicalModel::exists($entity)) {
            return $reject('entity_unknown', 'entity_type must be one of the canonical entities.', ['entities' => CanonicalModel::entities()]);
        }
        if (! in_array($entity, CanonicalModel::ingestible(), true)) {
            return $reject('entity_not_ingestible', "{$entity} records can be mapped and previewed but are not applied by the gateway yet.", ['ingestible' => CanonicalModel::ingestible()]);
        }
        if (! is_array($record) || $record === [] || array_is_list($record)) {
            return $reject('record_invalid', 'record must be a non-empty JSON object.');
        }
        $version = (int) ($envelope['schema_version'] ?? CanonicalModel::VERSION);
        if ($version !== CanonicalModel::VERSION) {
            return $reject('schema_version_unsupported', 'Unsupported schema_version.', ['supported' => [CanonicalModel::VERSION]]);
        }

        $hash = $this->hash($entity, $record);
        $existing = IntegrationMessage::query()->where('connector_id', $connector->id)->where('message_id', $messageId)->first();
        if ($existing !== null) {
            return $this->replay($existing, $entity, $hash, $reject);
        }

        try {
            $message = DB::transaction(fn () => IntegrationMessage::query()->create([
                'connector_id' => $connector->id, 'run_id' => $run->id, 'message_id' => $messageId, 'entity_type' => $entity,
                'schema_version' => $version, 'payload_hash' => $hash, 'payload' => $record, 'received_at' => now(),
            ]));
        } catch (UniqueConstraintViolationException) {
            // A parallel request stored it first.
            return $this->replay(IntegrationMessage::query()->where('connector_id', $connector->id)->where('message_id', $messageId)->firstOrFail(), $entity, $hash, $reject);
        }

        return [['message_id' => $messageId, 'status' => 'accepted', 'received_at' => $message->received_at->toIso8601String()], $message];
    }

    private function replay(IntegrationMessage $existing, string $entity, string $hash, callable $reject): array
    {
        if ($existing->payload_hash !== $hash || $existing->entity_type !== $entity) {
            return $reject('message_id_reused', 'A different record was already received under this message_id. Send changed data under a new message_id.', [
                'original_received_at' => $existing->received_at->toIso8601String(),
            ]);
        }

        return [$existing->acknowledgement(duplicate: true), null];
    }

    /** Key order does not change a record's identity. */
    private function hash(string $entity, array $record): string
    {
        $sort = function (array $a) use (&$sort): array {
            if (! array_is_list($a)) {
                ksort($a);
            }

            return array_map(fn ($v) => is_array($v) ? $sort($v) : $v, $a);
        };

        return hash('sha256', $entity.'|'.json_encode($sort($record), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}

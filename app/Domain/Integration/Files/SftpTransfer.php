<?php

namespace App\Domain\Integration\Files;

use App\Domain\Integration\Models\IntegrationConnector;
use App\Domain\Integration\Models\IntegrationRun;
use App\Support\Exceptions\BusinessRuleViolation;
use Illuminate\Contracts\Filesystem\Filesystem;
use Throwable;

/**
 * Scheduled file exchange with a connector's SFTP server.
 *
 * settings.sftp:
 *   host, port, root, host_fingerprint      connection (credentials are stored encrypted)
 *   inbound_path / archive_path / error_path  default inbound, archive, error
 *   files: [{pattern: "MAT_*.csv", entity_type: "product", format?: "csv"}]   first matching rule wins
 *   exports: [{dataset: "inventory", path: "outbound", interval_minutes: 60}]
 *
 * Files are handled rule by rule, in the order the rules are listed, so product files can be
 * listed before the batch and stock files that refer to them.
 *
 * An imported file (or an exact copy of one already imported) is moved to the
 * archive folder; a file that cannot be read is moved to the error folder and
 * recorded as a failed run. Files matching no rule are left in place.
 */
class SftpTransfer
{
    public function __construct(
        private readonly RemoteDisks $disks,
        private readonly FileImporter $importer,
        private readonly ExportService $exports,
    ) {}

    /** @return array{imported: int, duplicates: int, errors: int, skipped: int, exported: int} */
    public function poll(IntegrationConnector $connector): array
    {
        $summary = ['imported' => 0, 'duplicates' => 0, 'errors' => 0, 'skipped' => 0, 'exported' => 0];
        $sftp = $connector->setting('sftp', []);

        try {
            $disk = $this->disks->for($connector);
            $files = $disk->files($sftp['inbound_path'] ?? 'inbound');
        } catch (BusinessRuleViolation $e) {
            $this->fail($connector, $e->getMessage());
            throw $e;
        } catch (Throwable $e) {
            report($e);
            $this->fail($connector, 'Could not connect to the SFTP server or list the inbound folder ('.class_basename($e).').');

            throw new BusinessRuleViolation('sftp_unavailable', 'Could not connect to the SFTP server or list the inbound folder. Check the host, fingerprint and credentials.', 502);
        }

        // Rules run in their configured order (products before the batches and stock that refer to them),
        // files within a rule by name. A file is handled by the first rule it matches.
        sort($files);
        $queue = [];
        foreach ($files as $path) {
            $index = collect($sftp['files'] ?? [])->search(fn ($r) => fnmatch($r['pattern'] ?? '', basename($path)));
            $index === false ? $summary['skipped']++ : $queue[] = [$index, $path];
        }
        usort($queue, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        foreach ($queue as [$index, $path]) {
            $rule = $sftp['files'][$index];
            $name = basename($path);

            try {
                $result = $this->importer->import($connector, (string) $disk->get($path), $name, $rule['entity_type'], $rule['format'] ?? null, 'sftp');
                $summary[$result['duplicate_file'] ? 'duplicates' : 'imported']++;
                $this->move($disk, $path, $sftp['archive_path'] ?? 'archive');
            } catch (BusinessRuleViolation $e) {
                $summary['errors']++;
                $this->move($disk, $path, $sftp['error_path'] ?? 'error');
            }
        }

        foreach ($sftp['exports'] ?? [] as $export) {
            if ($this->exportDue($connector, $export)) {
                $file = $this->exports->export($connector, $export['dataset'], 'sftp');
                $disk->put(trim($export['path'] ?? 'outbound', '/').'/'.$file['file_name'], $file['content']);
                $summary['exported']++;
            }
        }

        $connector->forceFill(['last_transfer_at' => now()] + ($summary['errors'] === 0 ? ['last_error' => null] : []))->saveQuietly();

        return $summary;
    }

    private function exportDue(IntegrationConnector $connector, array $export): bool
    {
        $last = IntegrationRun::query()->where('connector_id', $connector->id)->where('direction', 'outbound')->where('source', 'sftp')
            ->where('dataset', $export['dataset'] ?? null)->max('started_at');

        return $last === null || now()->diffInMinutes($last, true) >= (int) ($export['interval_minutes'] ?? 60);
    }

    /** Timestamped, so a later file with the same name never overwrites an archived one. */
    private function move(Filesystem $disk, string $path, string $folder): void
    {
        $disk->move($path, trim($folder, '/').'/'.now()->format('Ymd-His').'-'.basename($path));
    }

    private function fail(IntegrationConnector $connector, string $message): void
    {
        $connector->forceFill(['last_failure_at' => now(), 'last_error' => mb_strimwidth($message, 0, 255), 'last_transfer_at' => now()])->saveQuietly();
    }
}

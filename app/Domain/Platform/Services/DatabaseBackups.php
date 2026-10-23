<?php

namespace App\Domain\Platform\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Logical backups and restore drills (Phase 10c, spec §31 "recovery procedures are tested").
 *
 * A backup is a consistent dump (`--single-transaction`: one snapshot, no table locks) compressed
 * with its SHA-256 and a manifest: when it was taken, the last migration, and the row count of every
 * table taken just before the snapshot. A drill restores a backup into a throwaway database, verifies
 * the checksum, the tables and their row counts, schema compatibility with this release and the
 * consistency invariants, then records how long the recovery took and how old the data was.
 */
class DatabaseBackups
{
    public const DRILL_CONNECTION = 'restore_drill';

    public function __construct(private readonly OperationsMonitor $monitor, private readonly ConsistencyChecker $consistency) {}

    /** @return array<string, mixed> the manifest */
    public function backup(): array
    {
        try {
            $manifest = $this->dump();
            $this->prune();
            $this->monitor->beat('backup');
            $this->monitor->report('backup', '', null, 'Backup '.$manifest['file'].' completed ('.number_format($manifest['bytes'] / 1048576, 1).' MB).');

            return $manifest;
        } catch (Throwable $e) {
            $this->monitor->report('backup', '', 'critical', 'The database backup failed: '.mb_substr($e->getMessage(), 0, 300));
            throw $e;
        }
    }

    /** @return list<array<string, mixed>> manifests, newest first */
    public function list(): array
    {
        $disk = Storage::disk(config('backup.disk'));

        return collect($disk->files(config('backup.path')))->filter(fn ($f) => str_ends_with($f, '.json'))
            ->map(fn ($f) => json_decode($disk->get($f), true))->filter()->sortByDesc('created_at')->values()->all();
    }

    /**
     * Restore a backup into a throwaway database and check it.
     *
     * @return array<string, mixed> the drill record
     */
    public function drill(?string $file = null, ?string $target = null, bool $keep = false): array
    {
        $connection = config('database.default');
        $live = config("database.connections.{$connection}");
        $target ??= $live['database'].'_restore_drill';
        if (! str_ends_with($target, '_restore_drill') || $target === $live['database']) {
            throw new RuntimeException("Refusing to restore into {$target}: the drill database name must end in _restore_drill.");
        }
        $manifest = $file ? collect($this->list())->firstWhere('file', basename($file)) : ($this->list()[0] ?? null);
        if ($manifest === null) {
            throw new RuntimeException('No backup found'.($file ? " named {$file}" : '').'.');
        }

        $started = now();
        $id = DB::table('recovery_drills')->insertGetId(['backup_file' => $manifest['file'], 'backup_created_at' => Carbon::parse($manifest['created_at']),
            'started_at' => $started, 'status' => 'running', 'created_at' => $started, 'updated_at' => $started]);
        $checks = [];
        $local = null;
        try {
            // 1. The file is what was written.
            $local = $this->download($manifest);
            $checks['checksum'] = hash_file('sha256', $local) === $manifest['sha256'] ? 'ok' : throw new RuntimeException('The backup file does not match its checksum.');

            // 2. Restore into a fresh database.
            DB::statement("DROP DATABASE IF EXISTS `{$target}`");
            DB::statement("CREATE DATABASE `{$target}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $import = new Process([config('backup.mysql'), '--host='.$live['host'], '--port='.$live['port'], '--user='.$live['username'], '--default-character-set=utf8mb4', $target],
                null, ['MYSQL_PWD' => (string) ($live['password'] ?? '')]);
            $import->setInput(fopen('compress.zlib://'.$local, 'r'))->setTimeout(null)->run();
            if (! $import->isSuccessful()) {
                throw new RuntimeException('Import failed: '.mb_substr(trim($import->getErrorOutput()), 0, 500));
            }
            $restoredAt = now();

            // 3. Check the copy, through a connection to it made the default for the duration.
            config(['database.connections.'.self::DRILL_CONNECTION => array_merge($live, ['database' => $target])]);
            DB::purge(self::DRILL_CONNECTION);
            DB::setDefaultConnection(self::DRILL_CONNECTION);
            try {
                $checks += $this->verify($manifest);
            } finally {
                DB::setDefaultConnection($connection);
                DB::purge(self::DRILL_CONNECTION);
            }

            $failed = array_filter($checks, fn ($c) => is_array($c) ? ($c['status'] ?? 'ok') !== 'ok' : $c !== 'ok');
            $status = $failed === [] ? 'passed' : 'failed';
            $record = ['status' => $status, 'restore_seconds' => (int) abs($started->diffInSeconds(now())), 'import_seconds' => (int) abs($started->diffInSeconds($restoredAt)),
                'data_age_seconds' => (int) abs(Carbon::parse($manifest['finished_at'])->diffInSeconds($started)), 'checks' => $checks];
            DB::table('recovery_drills')->where('id', $id)->update(['status' => $status, 'finished_at' => now(), 'restore_seconds' => $record['restore_seconds'],
                'data_age_seconds' => $record['data_age_seconds'], 'checks' => json_encode($checks), 'error' => $failed === [] ? null : 'Failed checks: '.implode(', ', array_keys($failed)), 'updated_at' => now()]);
            if ($status === 'passed') {
                $this->monitor->beat('restore_drill');
            }
            $this->monitor->report('restore_drill', '', $status === 'passed' ? null : 'critical',
                $status === 'passed' ? "Restore drill of {$manifest['file']} passed in {$record['restore_seconds']} s." : "Restore drill of {$manifest['file']} failed: ".implode(', ', array_keys($failed)).'.');

            return ['id' => $id, 'backup' => $manifest['file'], 'target' => $target] + $record;
        } catch (Throwable $e) {
            DB::setDefaultConnection($connection);
            DB::table('recovery_drills')->where('id', $id)->update(['status' => 'failed', 'finished_at' => now(), 'checks' => json_encode($checks),
                'error' => mb_substr($e->getMessage(), 0, 2000), 'updated_at' => now()]);
            $this->monitor->report('restore_drill', '', 'critical', 'Restore drill failed: '.mb_substr($e->getMessage(), 0, 300));
            throw $e;
        } finally {
            if ($local !== null) {
                @unlink($local);
            }
            if (! $keep) {
                DB::statement("DROP DATABASE IF EXISTS `{$target}`");
            }
        }
    }

    /** @return array<string, mixed> checks run against the restored copy (now the default connection) */
    private function verify(array $manifest): array
    {
        $checks = [];
        $restoredTables = collect(DB::select('SHOW FULL TABLES'))->map(fn ($r) => array_values((array) $r))->filter(fn ($r) => $r[1] === 'BASE TABLE')->map(fn ($r) => $r[0]);

        // Every table of the backup is there, and no durable table lost rows. Counts were taken just
        // before the dump's snapshot, so a restored table may hold more rows, never fewer.
        $missing = array_values(array_diff(array_keys($manifest['tables']), $restoredTables->all()));
        $short = [];
        foreach ($manifest['tables'] as $table => $rows) {
            if (in_array($table, config('backup.volatile_tables'), true) || in_array($table, $missing, true)) {
                continue;
            }
            $restored = DB::table($table)->count();
            if ($restored < $rows) {
                $short[$table] = ['backup' => $rows, 'restored' => $restored];
            }
        }
        $checks['tables'] = ['status' => $missing === [] && $short === [] ? 'ok' : 'failed', 'tables' => $restoredTables->count(), 'missing' => $missing, 'fewer_rows' => $short,
            'rows' => array_sum($manifest['tables'])];

        // Schema: this release knows every migration in the backup (a newer backup needs a newer release);
        // migrations of this release not yet in the backup are listed (they run on restore).
        $applied = DB::table('migrations')->pluck('migration')->all();
        $known = collect(File::files(database_path('migrations')))->map(fn ($f) => $f->getFilenameWithoutExtension())->all();
        $unknown = array_values(array_diff($applied, $known));
        $checks['schema'] = ['status' => $unknown === [] ? 'ok' : 'failed', 'last_migration' => end($applied) ?: null,
            'pending_for_this_release' => array_values(array_diff($known, $applied)), 'unknown_to_this_release' => $unknown];

        $problems = $this->consistency->check();
        $checks['consistency'] = ['status' => array_filter($problems) === [] ? 'ok' : 'failed', 'problems' => array_filter($problems)];

        return $checks;
    }

    /** @return array<string, mixed> */
    private function dump(): array
    {
        $connection = config('database.default');
        $c = config("database.connections.{$connection}");
        $createdAt = now()->utc();
        $name = $createdAt->format('Y-m-d\THis\Z').'-'.$c['database'];
        $tmp = tempnam(sys_get_temp_dir(), 'bkp');

        // Row counts just before the snapshot (see verify()).
        $tables = collect(DB::select('SHOW FULL TABLES'))->map(fn ($r) => array_values((array) $r))->filter(fn ($r) => $r[1] === 'BASE TABLE')->map(fn ($r) => $r[0]);
        $counts = $tables->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
        $lastMigration = DB::table('migrations')->orderByDesc('id')->value('migration');

        $gz = gzopen($tmp, 'wb6');
        $errors = '';
        $process = new Process([config('backup.mysqldump'), '--single-transaction', '--quick', '--hex-blob', '--no-tablespaces', '--skip-lock-tables',
            '--default-character-set=utf8mb4', '--host='.$c['host'], '--port='.$c['port'], '--user='.$c['username'], $c['database']],
            null, ['MYSQL_PWD' => (string) ($c['password'] ?? '')]);
        $process->setTimeout(null)->run(function ($type, $buffer) use ($gz, &$errors) {
            $type === Process::OUT ? gzwrite($gz, $buffer) : $errors .= $buffer;
        });
        gzclose($gz);
        if (! $process->isSuccessful()) {
            @unlink($tmp);
            throw new RuntimeException('mysqldump failed: '.mb_substr(trim($errors), 0, 500).' (set MYSQLDUMP_PATH if it is not on PATH)');
        }

        $manifest = ['file' => "{$name}.sql.gz", 'database' => $c['database'], 'created_at' => $createdAt->toIso8601String(), 'finished_at' => now()->utc()->toIso8601String(),
            'bytes' => filesize($tmp), 'sha256' => hash_file('sha256', $tmp), 'last_migration' => $lastMigration, 'tables' => $counts, 'app_version' => config('app.version')];

        $disk = Storage::disk(config('backup.disk'));
        $stream = fopen($tmp, 'r');
        $disk->writeStream(config('backup.path')."/{$manifest['file']}", $stream);
        is_resource($stream) && fclose($stream);
        @unlink($tmp);
        $disk->put(config('backup.path')."/{$name}.json", json_encode($manifest, JSON_PRETTY_PRINT));

        return $manifest;
    }

    private function download(array $manifest): string
    {
        $local = tempnam(sys_get_temp_dir(), 'rst');
        $in = Storage::disk(config('backup.disk'))->readStream(config('backup.path')."/{$manifest['file']}") ?? throw new RuntimeException("Backup file {$manifest['file']} is missing.");
        $out = fopen($local, 'w');
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);

        return $local;
    }

    /** Delete backups past the retention period, always keeping the newest few. */
    private function prune(): void
    {
        $disk = Storage::disk(config('backup.disk'));
        $cutoff = now()->subDays((int) config('backup.retention_days'));
        foreach (array_slice($this->list(), (int) config('backup.keep_minimum')) as $m) {
            if (Carbon::parse($m['created_at'])->lt($cutoff)) {
                $disk->delete([config('backup.path')."/{$m['file']}", config('backup.path').'/'.str_replace('.sql.gz', '.json', $m['file'])]);
            }
        }
    }
}

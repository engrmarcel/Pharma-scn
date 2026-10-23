<?php

namespace App\Console\Commands;

use App\Domain\Platform\Services\DatabaseBackups;
use Carbon\CarbonInterval;
use Illuminate\Console\Command;
use Throwable;

/**
 * Restore a backup into a throwaway database (name ending in _restore_drill), check it, and record
 * the measured recovery time and data age. Never touches the live database.
 */
class RestoreDrill extends Command
{
    protected $signature = 'ops:restore-drill
        {--file= : Backup file name (default: the newest)}
        {--database= : Drill database (must end in _restore_drill; default: <live>_restore_drill)}
        {--keep : Keep the restored database for inspection}
        {--list : List available backups}';

    protected $description = 'Restore a backup into a throwaway database and verify it (restore drill)';

    public function handle(DatabaseBackups $backups): int
    {
        if ($this->option('list')) {
            $this->table(['File', 'Taken', 'MB', 'Last migration'], collect($backups->list())->map(fn ($m) => [$m['file'], $m['created_at'],
                number_format($m['bytes'] / 1048576, 1), $m['last_migration']])->all());

            return self::SUCCESS;
        }

        try {
            $r = $backups->drill($this->option('file'), $this->option('database'), (bool) $this->option('keep'));
        } catch (Throwable $e) {
            $this->error('Restore drill failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->table(['Check', 'Result'], collect($r['checks'])->map(fn ($c, $name) => [$name, is_array($c) ? ($c['status'] ?? 'ok') : $c])->values()->all());
        $this->line(sprintf('Backup %s restored into %s: recovery took %d s (import %d s); the data was %s old.', $r['backup'], $r['target'],
            $r['restore_seconds'], $r['import_seconds'], CarbonInterval::seconds($r['data_age_seconds'])->cascade()->forHumans(short: true)));
        if ($r['status'] !== 'passed') {
            $this->error('The drill FAILED; see recovery_drills #'.$r['id'].'.');

            return self::FAILURE;
        }
        $this->info('The drill passed.');

        return self::SUCCESS;
    }
}

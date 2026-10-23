<?php

namespace App\Console\Commands;

use App\Domain\Platform\Services\DatabaseBackups;
use Illuminate\Console\Command;
use Throwable;

/** Consistent, compressed dump with checksum and manifest to the backup disk; prunes old backups. */
class BackupDatabase extends Command
{
    protected $signature = 'ops:backup';

    protected $description = 'Back up the database (consistent dump, checksum and manifest) to the backup disk';

    public function handle(DatabaseBackups $backups): int
    {
        try {
            $m = $backups->backup();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info(sprintf('Backed up %s: %s tables, %s rows, %.1f MB, sha256 %s', $m['database'], count($m['tables']),
            number_format(array_sum($m['tables'])), $m['bytes'] / 1048576, substr($m['sha256'], 0, 16).'…'));

        return self::SUCCESS;
    }
}

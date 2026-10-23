<?php

namespace App\Console\Commands;

use App\Domain\Platform\Services\ConsistencyChecker;
use App\Domain\Platform\Services\OperationsMonitor;
use Illuminate\Console\Command;

/**
 * Invariants that must hold whatever the load (Phase 10 acceptance: transactional consistency is
 * preserved). Read-only. Run after a load test, after a restore, or on a schedule; exits non-zero
 * when anything is broken, and lists what.
 *
 *   php artisan ops:consistency [--organization=ULID] [--alert]
 */
class CheckConsistency extends Command
{
    protected $signature = 'ops:consistency {--organization= : Check one organization only} {--alert : Open or resolve an operational alert with the outcome (scheduled run)}';

    protected $description = 'Check stock, reservation, ledger, POS and traceability invariants';

    public function handle(ConsistencyChecker $checker, OperationsMonitor $monitor): int
    {
        $org = $this->option('organization');
        $checks = $checker->check($org);
        $failed = array_filter($checks);

        if ($this->option('alert')) {
            $monitor->report('consistency', $org ?? '', $failed === [] ? null : 'critical',
                $failed === [] ? 'All consistency invariants hold.' : 'Consistency problems: '.collect($failed)->map(fn ($n, $k) => str_replace('_', ' ', $k)." ({$n})")->implode(', ').'.',
                array_sum($failed));
        }

        $this->table(['Check', 'Problems'], collect($checks)->map(fn ($n, $name) => [str_replace('_', ' ', $name), $n === 0 ? 'none' : $n])->values()->all());
        if ($failed !== []) {
            $this->error('Consistency problems found: '.implode(', ', array_keys($failed)));

            return self::FAILURE;
        }
        $this->info('All invariants hold.');

        return self::SUCCESS;
    }
}

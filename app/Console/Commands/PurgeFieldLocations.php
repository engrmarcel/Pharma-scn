<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * GPS retention control (spec §12.2): removes raw check-in/check-out
 * coordinates, accuracy and device context from visits older than the
 * retention period. The verification result and distance are kept, so
 * reports stay truthful without holding precise location history.
 */
class PurgeFieldLocations extends Command
{
    protected $signature = 'field-force:purge-locations {--days= : Override field_force.location_retention_days}';

    protected $description = 'Remove raw visit coordinates older than the retention period';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('field_force.location_retention_days'));
        if ($days <= 0) {
            $this->info('Location pruning is disabled.');

            return self::SUCCESS;
        }

        $cleared = [];
        foreach (['check_in', 'check_out'] as $e) {
            foreach (['latitude', 'longitude', 'accuracy_m', 'device'] as $c) {
                $cleared["{$e}_{$c}"] = null;
            }
        }

        // Crosses tenants on purpose: retention is a platform policy, run by the scheduler.
        $count = DB::table('field_visits')->whereNull('location_purged_at')->where('check_in_at', '<', now()->subDays($days))
            ->update($cleared + ['location_purged_at' => now(), 'updated_at' => now()]);

        $this->info("Removed location data from {$count} visits older than {$days} days.");

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Domain\Analytics\Services\DemandFacts;
use App\Domain\Organizations\Models\Organization;
use Illuminate\Console\Command;

/**
 * Rebuilds the analytical store from the operational ledger. Nightly it recomputes the
 * last few days (late postings, offline sales synchronised later); --full rebuilds the
 * whole history window, e.g. after enabling analytics for an organization.
 */
class RefreshAnalytics extends Command
{
    protected $signature = 'analytics:refresh {--organization= : one organization id} {--days= : days back to recompute} {--full : rebuild the whole history window}';

    protected $description = 'Rebuild the daily demand facts used by analytics and forecasting';

    public function handle(DemandFacts $facts): int
    {
        $days = $this->option('full') ? config('analytics.demand_history_days') : (int) ($this->option('days') ?: config('analytics.refresh_recent_days'));
        $organizations = Organization::query()->where('status', 'active')
            ->when($this->option('organization'), fn ($q, $id) => $q->whereKey($id))->get();

        foreach ($organizations as $organization) {
            if (! $organization->hasModule('inventory') && ! $organization->hasModule('pos')) {
                continue;
            }
            $result = $facts->refresh($organization->id, today()->subDays($days - 1), today());
            $this->line("{$organization->id}: {$result['rows']} rows, {$result['from']} to {$result['to']}");
        }

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Domain\Analytics\Services\RecommendationEngine;
use App\Domain\Organizations\Models\Organization;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/** Nightly advisory recommendations for every active organization with the analytics module. */
class GenerateRecommendations extends Command
{
    protected $signature = 'analytics:recommend {--organization= : one organization id}';

    protected $description = 'Generate reorder, overstock, expiry-risk and anomaly recommendations (advisory only)';

    public function handle(TenantContext $tenant): int
    {
        $organizations = Organization::query()->where('status', 'active')->when($this->option('organization'), fn ($q, $id) => $q->whereKey($id))->get();
        foreach ($organizations as $organization) {
            if (! $organization->hasModule('analytics')) {
                continue;
            }
            $result = $tenant->runAs($organization, fn () => app(RecommendationEngine::class)->generate());
            $this->line("{$organization->id}: ".json_encode($result));
        }

        return self::SUCCESS;
    }
}

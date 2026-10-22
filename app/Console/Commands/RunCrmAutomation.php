<?php

namespace App\Console\Commands;

use App\Domain\Crm\Services\CrmAutomation;
use App\Domain\Organizations\Models\Organization;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/** Daily follow-up automation for every active organization with the field-force module. */
class RunCrmAutomation extends Command
{
    protected $signature = 'crm:automation';

    protected $description = 'Create automated CRM follow-ups (missed plans, overdue visits, stale opportunities, escalations)';

    public function handle(TenantContext $tenant): int
    {
        foreach (Organization::query()->where('status', 'active')->get() as $organization) {
            if (! $organization->hasModule('field_force')) {
                continue;
            }
            $result = $tenant->runAs($organization, fn () => app(CrmAutomation::class)->run());
            $this->line("{$organization->id}: ".json_encode($result));
        }

        return self::SUCCESS;
    }
}

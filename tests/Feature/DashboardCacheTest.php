<?php

namespace Tests\Feature;

use App\Domain\Access\Models\Role;
use App\Domain\Organizations\Models\OrganizationModule;
use Illuminate\Support\Carbon;
use Tests\FeatureTestCase;

/** Dashboards are kept briefly per viewer (Phase 10), but a change in access applies at once. */
class DashboardCacheTest extends FeatureTestCase
{
    public function test_a_dashboard_is_reused_for_the_same_viewer_and_recomputed_when_access_changes(): void
    {
        config(['analytics.dashboard_cache_seconds' => 60]);
        [$manager, $org, $membership] = $this->organizationMember(['sales_manager']);
        OrganizationModule::query()->firstOrCreate(['organization_id' => $org->id, 'module' => 'analytics'], ['enabled' => true]);

        $this->actingAsApi($manager, $org);
        $first = $this->api('GET', 'analytics/sales')->assertOk()->json('meta.generated_at');
        Carbon::setTestNow(now()->addSeconds(20));
        $this->assertSame($first, $this->api('GET', 'analytics/sales')->assertOk()->json('meta.generated_at'), 'served from the cache');
        $this->assertNotSame($first, $this->api('GET', 'analytics/sales?from='.now()->subDays(3)->toDateString())->assertOk()->json('meta.generated_at'), 'other parameters, other entry');

        // Losing the permission takes effect immediately, not after the cache expires.
        $membership->roles()->sync(Role::query()->whereNull('organization_id')->where('code', 'procurement_officer')->pluck('id'));
        $this->actingAsApi($manager, $org);
        $this->api('GET', 'analytics/sales')->assertForbidden();

        Carbon::setTestNow(now()->addSeconds(61));
        $membership->roles()->sync(Role::query()->whereNull('organization_id')->where('code', 'sales_manager')->pluck('id'));
        $this->actingAsApi($manager, $org);
        $this->assertNotSame($first, $this->api('GET', 'analytics/sales')->assertOk()->json('meta.generated_at'), 'expired');
        Carbon::setTestNow();
    }
}

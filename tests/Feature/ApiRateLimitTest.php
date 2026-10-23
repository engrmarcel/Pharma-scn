<?php

namespace Tests\Feature;

use App\Domain\Billing\Services\ApiRateLimits;
use App\Domain\Billing\Services\EntitlementService;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Tests\FeatureTestCase;

/** The per-user API rate follows the plan's api_requests_per_minute entitlement (found by the Phase 10 load test). */
class ApiRateLimitTest extends FeatureTestCase
{
    public function test_rate_follows_the_organization_plan_and_overrides(): void
    {
        [$owner, $org] = $this->organizationMember();
        $limits = app(ApiRateLimits::class);
        $entitlements = app(EntitlementService::class);
        $planLimit = $entitlements->entitlements($org)['api_requests_per_minute'];

        $this->assertSame(config('platform.api.default_rate_per_minute'), $limits->perMinute($owner, null), 'no organization: platform default');
        $this->assertSame($planLimit['limit'] ?? config('platform.api.unlimited_rate_ceiling'), $limits->perMinute($owner, $org->id));

        $entitlements->setOverride($org, 'api_requests_per_minute', true, 3, 'Test', $owner);
        Cache::flush();
        $this->assertSame(3, $limits->perMinute($owner, $org->id));

        $entitlements->setOverride($org, 'api_requests_per_minute', true, null, 'Unlimited', $owner);
        Cache::flush();
        $this->assertSame(config('platform.api.unlimited_rate_ceiling'), $limits->perMinute($owner, $org->id), 'unlimited keeps a platform ceiling');

        // Naming an organization you do not belong to does not borrow its rate.
        $stranger = User::factory()->create();
        $this->assertSame(config('platform.api.default_rate_per_minute'), $limits->perMinute($stranger, $org->id));
    }

    public function test_requests_beyond_the_plan_rate_are_refused(): void
    {
        [$owner, $org] = $this->organizationMember();
        app(EntitlementService::class)->setOverride($org, 'api_requests_per_minute', true, 3, 'Test', $owner);
        Cache::flush();

        $this->actingAsApi($owner, $org);
        foreach (range(1, 3) as $i) {
            $this->api('GET', 'organization')->assertOk();
        }
        $this->api('GET', 'organization')->assertStatus(429);
    }
}

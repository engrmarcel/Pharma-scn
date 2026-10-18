<?php

namespace Tests\Feature;

use App\Domain\Billing\Models\SubscriptionPlan;
use App\Domain\Billing\Services\EntitlementService;
use App\Domain\Organizations\Models\Branch;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Support\Facades\Notification;
use Tests\FeatureTestCase;

class SubscriptionEntitlementTest extends FeatureTestCase
{
    public function test_new_organizations_start_on_the_trial_plan_and_can_see_usage(): void
    {
        [$owner, $org] = $this->organizationMember();

        $this->actingAsApi($owner, $org)->api('GET', 'organization/subscription')
            ->assertOk()
            ->assertJsonPath('data.plan.code', 'trial')
            ->assertJsonPath('data.status', 'trialing')
            ->assertJsonPath('data.operational', true)
            ->assertJsonPath('data.entitlements.branches.limit', 3)
            ->assertJsonPath('data.usage.users', 1);
    }

    public function test_branch_limit_cannot_be_bypassed_through_the_api(): void
    {
        [$owner, $org] = $this->organizationMember();
        $ops = $this->platformUser('platform_operations_admin');
        $this->actingAsApi($ops)->api('PUT', "admin/organizations/{$org->id}/entitlement-overrides", [
            'key' => 'branches', 'enabled' => true, 'limit' => 1, 'reason' => 'test cap',
        ])->assertOk();

        $this->actingAsApi($owner, $org)->api('POST', 'branches', ['code' => 'B1', 'name' => 'One'])->assertCreated();
        $this->api('POST', 'branches', ['code' => 'B2', 'name' => 'Two'])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'entitlement_limit_reached')
            ->assertJsonPath('error.details.limit', 1);

        $this->assertSame(1, $this->inTenant($org, fn () => Branch::query()->count()));
    }

    public function test_pending_invitations_consume_user_seats(): void
    {
        Notification::fake();
        [$owner, $org] = $this->organizationMember();
        app(EntitlementService::class)->setOverride($org, 'users', true, 2, 'test', $owner);

        $this->actingAsApi($owner, $org)->api('POST', 'invitations', ['email' => 'a@example.test', 'roles' => ['pharmacist']])->assertCreated();
        $this->api('POST', 'invitations', ['email' => 'b@example.test', 'roles' => ['pharmacist']])
            ->assertForbidden()->assertJsonPath('error.code', 'entitlement_limit_reached');
    }

    public function test_inactive_subscription_blocks_new_records_but_keeps_existing_ones_readable(): void
    {
        [$owner, $org] = $this->organizationMember();
        $this->inTenant($org, fn () => Branch::query()->create(['code' => 'HQ', 'name' => 'HQ']));
        $billing = $this->platformUser('platform_operations_admin');

        $this->actingAsApi($billing)->api('PUT', "admin/organizations/{$org->id}/subscription", ['status' => 'expired'])
            ->assertOk()->assertJsonPath('data.operational', false);

        $this->actingAsApi($owner, $org)->api('GET', 'branches')->assertOk()->assertJsonCount(1, 'data');
        $this->api('POST', 'branches', ['code' => 'NEW', 'name' => 'New'])
            ->assertForbidden()->assertJsonPath('error.code', 'subscription_inactive');
        $this->api('GET', 'catalogue/items')->assertForbidden()->assertJsonPath('error.code', 'module_not_entitled');
    }

    public function test_module_requires_both_enablement_and_entitlement(): void
    {
        [$owner, $org] = $this->organizationMember();
        app(EntitlementService::class)->setOverride($org, 'module.catalogue', false, null, 'test', $owner);

        $this->actingAsApi($owner, $org)->api('GET', 'catalogue/items')
            ->assertForbidden()->assertJsonPath('error.code', 'module_not_entitled');
    }

    public function test_plan_change_keeps_history_and_is_audited(): void
    {
        [, $org] = $this->organizationMember();
        $billing = $this->platformUser('platform_operations_admin');

        $this->actingAsApi($billing)->api('PUT', "admin/organizations/{$org->id}/subscription", ['plan_code' => 'pharmacy'])
            ->assertOk()->assertJsonPath('data.plan.code', 'pharmacy')->assertJsonPath('data.status', 'active');

        $history = $this->api('GET', "admin/organizations/{$org->id}/subscription")->json('data.history');
        $this->assertSame(['pharmacy', 'trial'], array_column($history, 'plan'));
        $this->assertNotNull($history[1]['ended_at']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'subscription.changed', 'organization_id' => $org->id]);
    }

    public function test_billing_staff_can_edit_plans_and_tenants_cannot(): void
    {
        [$owner, $org] = $this->organizationMember();
        $billing = $this->platformUser('platform_operations_admin');
        $plan = SubscriptionPlan::query()->where('code', 'pharmacy')->first();

        $this->actingAsApi($owner, $org)->api('GET', 'admin/subscription-plans')->assertForbidden();
        $this->api('PUT', "admin/organizations/{$org->id}/subscription", ['plan_code' => 'enterprise'])->assertForbidden();

        $this->actingAsApi($billing)->api('PATCH', "admin/subscription-plans/{$plan->id}", [
            'price' => '25000.0000', 'entitlements' => ['users' => ['enabled' => true, 'limit' => 15]],
        ])->assertOk()->assertJsonPath('data.price', '25000.0000')->assertJsonPath('data.entitlements.users.limit', 15);

        $this->api('PATCH', "admin/subscription-plans/{$plan->id}", ['entitlements' => ['bogus' => ['enabled' => true]]])
            ->assertUnprocessable();
    }

    public function test_cashier_cannot_view_billing(): void
    {
        [$cashier, $org] = $this->organizationMember(['pos_cashier']);

        $this->actingAsApi($cashier, $org)->api('GET', 'organization/subscription')->assertForbidden();
    }

    public function test_plan_seeder_does_not_overwrite_commercial_edits(): void
    {
        $plan = SubscriptionPlan::query()->where('code', 'starter')->first();
        $plan->forceFill(['price' => '9999.0000'])->save();
        $plan->setEntitlement('users', true, 7);

        $this->seed(SubscriptionPlanSeeder::class);

        $this->assertSame('9999.0000', $plan->fresh()->price);
        $this->assertSame(7, $plan->fresh()->entitlementMap()['users']['limit']);
    }
}

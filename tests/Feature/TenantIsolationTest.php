<?php

namespace Tests\Feature;

use App\Domain\Catalogue\Models\OrganizationProduct;
use App\Domain\Organizations\Models\Branch;
use App\Jobs\Middleware\TenantAware;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use LogicException;
use Tests\FeatureTestCase;

class TenantIsolationTest extends FeatureTestCase
{
    public function test_tenant_routes_require_an_organization(): void
    {
        [$user] = $this->organizationMember();

        $this->actingAsApi($user)->api('GET', 'branches')
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'organization_required');
    }

    public function test_user_cannot_act_in_an_organization_they_do_not_belong_to(): void
    {
        [$alice] = $this->organizationMember();
        [, $orgB] = $this->organizationMember();

        $this->actingAsApi($alice, $orgB)->api('GET', 'branches')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'organization_access_denied');
    }

    public function test_malformed_and_unknown_organization_ids_get_the_same_answer(): void
    {
        [$alice] = $this->organizationMember();
        $this->actingAsApi($alice);

        foreach (['not-a-ulid', '01JAAAAAAAAAAAAAAAAAAAAAAA'] as $id) {
            $this->api('GET', 'branches', headers: ['X-Organization-Id' => $id])
                ->assertForbidden()
                ->assertJsonPath('error.code', 'organization_access_denied');
        }
    }

    public function test_records_of_another_tenant_are_invisible_and_unmodifiable(): void
    {
        [$alice, $orgA] = $this->organizationMember();
        [$bob, $orgB] = $this->organizationMember();

        $bobBranch = $this->inTenant($orgB, fn () => Branch::query()->create(['code' => 'B-HQ', 'name' => 'Bob HQ']));
        $this->inTenant($orgA, fn () => Branch::query()->create(['code' => 'A-HQ', 'name' => 'Alice HQ']));

        $this->actingAsApi($alice, $orgA)->api('GET', 'branches')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'A-HQ');

        $this->api('PATCH', "branches/{$bobBranch->id}", ['name' => 'Hijacked'])->assertNotFound();
        $this->assertSame('Bob HQ', $bobBranch->fresh()->name);
    }

    public function test_client_supplied_organization_id_in_body_is_ignored(): void
    {
        [$alice, $orgA] = $this->organizationMember();
        [, $orgB] = $this->organizationMember();

        $this->actingAsApi($alice, $orgA)
            ->api('POST', 'branches', ['code' => 'X1', 'name' => 'Sneaky', 'organization_id' => $orgB->id])
            ->assertCreated();

        $this->assertDatabaseHas('branches', ['code' => 'X1', 'organization_id' => $orgA->id]);
        $this->assertDatabaseMissing('branches', ['code' => 'X1', 'organization_id' => $orgB->id]);
    }

    public function test_warehouse_cannot_reference_another_tenants_branch(): void
    {
        [$alice, $orgA] = $this->organizationMember();
        [, $orgB] = $this->organizationMember();
        $foreignBranch = $this->inTenant($orgB, fn () => Branch::query()->create(['code' => 'B1', 'name' => 'B']));

        $this->actingAsApi($alice, $orgA)
            ->api('POST', 'warehouses', ['code' => 'W1', 'name' => 'Main', 'branch_id' => $foreignBranch->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('branch_id');
    }

    public function test_tenant_scope_fails_closed_without_context(): void
    {
        [, $orgA] = $this->organizationMember();
        $this->inTenant($orgA, fn () => Branch::query()->create(['code' => 'A1', 'name' => 'A']));

        $this->assertSame(0, Branch::query()->count());
        $this->assertSame(1, Branch::acrossTenants()->count());
    }

    public function test_creating_tenant_records_without_context_is_refused(): void
    {
        $this->expectException(LogicException::class);

        Branch::query()->create(['code' => 'NOPE', 'name' => 'No tenant']);
    }

    public function test_creating_a_record_for_a_different_tenant_inside_a_context_is_refused(): void
    {
        [, $orgA] = $this->organizationMember();
        [, $orgB] = $this->organizationMember();

        $this->expectException(LogicException::class);

        // organization_id is guarded against mass assignment; forceFill exercises the trait's own check.
        $this->inTenant($orgA, fn () => (new Branch)->forceFill(['code' => 'X', 'name' => 'X', 'organization_id' => $orgB->id])->save());
    }

    public function test_tenant_aware_jobs_run_in_the_dispatching_tenant(): void
    {
        [, $orgA] = $this->organizationMember();
        [, $orgB] = $this->organizationMember();
        $this->inTenant($orgA, fn () => Branch::query()->create(['code' => 'A1', 'name' => 'A']));
        $this->inTenant($orgB, fn () => Branch::query()->create(['code' => 'B1', 'name' => 'B']));

        CountBranchesJob::$seen = null;
        // Captured for B, then executed while a different tenant (A) is current:
        // the job must still see only B's data.
        $job = $this->inTenant($orgB, fn () => new CountBranchesJob);
        $this->inTenant($orgA, function () use ($job, $orgA) {
            dispatch($job);
            $this->assertSame($orgA->id, app(TenantContext::class)->id(), 'context must be restored after the job');
        });

        $this->assertSame(['B1'], CountBranchesJob::$seen);
    }

    public function test_same_sku_can_exist_in_two_organizations_without_collision(): void
    {
        $product = $this->makeProduct();
        [$alice, $orgA] = $this->organizationMember();
        [$bob, $orgB] = $this->organizationMember();

        $this->actingAsApi($alice, $orgA)->api('POST', 'catalogue/items', ['product_id' => $product->id, 'sku' => 'AMX-500'])->assertCreated();
        $this->actingAsApi($bob, $orgB)->api('POST', 'catalogue/items', ['product_id' => $product->id, 'sku' => 'AMX-500'])->assertCreated();

        $this->actingAsApi($alice, $orgA)->api('GET', 'catalogue/items')->assertJsonCount(1, 'data');
        $this->assertSame(2, OrganizationProduct::acrossTenants()->count());
    }

    public function test_audit_trail_is_limited_to_the_current_tenant(): void
    {
        [$alice, $orgA] = $this->organizationMember();
        [, $orgB] = $this->organizationMember();

        $this->actingAsApi($alice, $orgA)->api('GET', 'audit-logs')
            ->assertOk()
            ->assertJsonMissing(['id' => $orgB->id]);

        $actions = collect($this->api('GET', 'audit-logs')->json('data'))->pluck('subject.id');
        $this->assertNotContains($orgB->id, $actions->all());
        $this->assertContains($orgA->id, $actions->all());
    }

    public function test_suspended_organization_blocks_its_members(): void
    {
        [$alice, $orgA] = $this->organizationMember();
        $orgA->forceFill(['status' => 'suspended'])->save();

        $this->actingAsApi($alice, $orgA)->api('GET', 'organization')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'organization_suspended');
    }
}

class CountBranchesJob implements ShouldQueue
{
    use Dispatchable, Queueable, TenantAware;

    public static ?array $seen = null;

    public function __construct()
    {
        $this->captureTenant();
    }

    public function handle(): void
    {
        self::$seen = Branch::query()->pluck('code')->all();
    }
}

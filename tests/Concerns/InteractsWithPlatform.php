<?php

namespace Tests\Concerns;

use App\Domain\Access\Models\Role;
use App\Domain\Access\Services\AccessControl;
use App\Domain\Catalogue\Models\Product;
use App\Domain\Catalogue\Services\ProductCatalogueService;
use App\Domain\Organizations\Enums\VerificationStatus;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Organizations\Services\OrganizationOnboardingService;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

trait InteractsWithPlatform
{
    protected ?Organization $actingOrganization = null;

    protected function platformUser(string $roleCode): User
    {
        $user = User::factory()->create();
        $user->platformRoles()->attach(Role::query()->platform()->where('code', $roleCode)->firstOrFail());

        return $user;
    }

    /**
     * An organization with an owner, optionally verified, plus a member with the given roles.
     *
     * @return array{0: User, 1: Organization, 2: OrganizationMembership}
     */
    protected function organizationMember(array $roles = ['org_owner'], array $orgAttributes = [], bool $verified = false, ?Organization $organization = null): array
    {
        if ($organization === null) {
            $owner = User::factory()->create();
            $organization = app(OrganizationOnboardingService::class)->register($owner, Organization::factory()->raw($orgAttributes));
            if ($verified) {
                $organization->forceFill(['verification_status' => VerificationStatus::Verified, 'verified_at' => now()])->save();
            }
            if ($roles === ['org_owner']) {
                return [$owner, $organization->fresh(), $organization->memberships()->where('user_id', $owner->id)->first()];
            }
        }

        $user = User::factory()->create();
        $membership = $organization->memberships()->create(['user_id' => $user->id, 'status' => 'active', 'joined_at' => now()]);
        $membership->roles()->attach(Role::query()->whereNull('organization_id')->where('scope', 'organization')->whereIn('code', $roles)->pluck('id'));

        return [$user, $organization->fresh(), $membership];
    }

    protected function actingAsApi(User $user, ?Organization $organization = null): static
    {
        Sanctum::actingAs($user);
        app(AccessControl::class)->flush();
        $this->actingOrganization = $organization;

        return $this;
    }

    protected function withTenant(?Organization $organization): static
    {
        $this->actingOrganization = $organization;

        return $this;
    }

    protected function api(string $method, string $uri, array $data = [], array $headers = []): TestResponse
    {
        if ($this->actingOrganization) {
            $headers[config('platform.tenancy.header')] ??= $this->actingOrganization->id;
        }

        return $this->json($method, '/api/v1/'.ltrim($uri, '/'), $data, $headers);
    }

    protected function productPayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'generic_name' => 'Amoxicillin',
            'brand_name' => 'Testmox',
            'strength_text' => '500 mg',
            'dosage_form_code' => 'capsule',
            'route_code' => 'oral',
            'country_of_origin' => 'IN',
            'ingredients' => [['name' => 'Amoxicillin trihydrate', 'strength_value' => 500, 'strength_unit' => 'mg']],
            'package_levels' => [
                ['level' => 0, 'code' => 'capsule', 'name' => 'Capsule', 'unit_code' => 'capsule'],
                ['level' => 1, 'code' => 'blister', 'name' => 'Blister of 10', 'unit_code' => 'blister', 'quantity_of_child' => 10],
                ['level' => 2, 'code' => 'pack', 'name' => 'Pack of 2 blisters', 'unit_code' => 'pack', 'quantity_of_child' => 2],
                ['level' => 3, 'code' => 'carton', 'name' => 'Carton of 50 packs', 'unit_code' => 'carton', 'quantity_of_child' => 50],
            ],
        ], $overrides);
    }

    protected function makeProduct(array $overrides = []): Product
    {
        $actor = User::factory()->create();

        return app(ProductCatalogueService::class)->create($this->productPayload($overrides + ['status' => 'active']), $actor);
    }

    protected function inTenant(Organization $organization, callable $callback): mixed
    {
        return app(TenantContext::class)->runAs($organization, $callback);
    }
}

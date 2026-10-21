<?php

namespace App\Domain\Organizations\Services;

use App\Domain\Access\Models\Role;
use App\Domain\Billing\Models\SubscriptionPlan;
use App\Domain\Billing\Services\EntitlementService;
use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Organizations\Events\OrganizationRegistered;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationModule;
use App\Domain\Platform\Services\AuditLogger;
use App\Models\User;
use App\Support\Events\DomainEvents;
use Illuminate\Support\Facades\DB;

class OrganizationOnboardingService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly EntitlementService $entitlements,
    ) {}

    /**
     * Register an organization with the given user as its owner. The
     * organization starts unverified; verification is a separate workflow.
     */
    public function register(User $owner, array $data): Organization
    {
        return DB::transaction(function () use ($owner, $data) {
            $organization = Organization::query()->create($data + ['created_by' => $owner->id]);
            $organization->refresh();

            $membership = $organization->memberships()->create([
                'user_id' => $owner->id,
                'status' => 'active',
                'all_locations' => true,
                'joined_at' => now(),
            ]);
            $membership->roles()->attach(Role::query()->whereNull('organization_id')->where('scope', 'organization')->where('code', 'org_owner')->firstOrFail());

            $type = $organization->type instanceof OrganizationType ? $organization->type->value : $organization->type;
            $modules = config("platform.default_modules.$type", config('platform.default_modules.*'));
            foreach ($modules as $module) {
                OrganizationModule::query()->create(['organization_id' => $organization->id, 'module' => $module, 'enabled' => true, 'changed_by' => $owner->id]);
            }

            $this->entitlements->subscribe($organization, SubscriptionPlan::query()->where('code', config('entitlements.default_plan'))->firstOrFail(), $owner);

            $this->audit->record('organization.registered', $organization, new: $organization->toArray() + ['modules' => $modules], organizationId: $organization->id);
            DomainEvents::raise(new OrganizationRegistered($organization->id));

            return $organization;
        });
    }
}

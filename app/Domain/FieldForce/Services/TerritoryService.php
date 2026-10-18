<?php

namespace App\Domain\FieldForce\Services;

use App\Domain\Billing\Services\EntitlementService;
use App\Domain\FieldForce\Models\Territory;
use App\Domain\FieldForce\Models\TerritoryMember;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Parties\Models\Customer;
use App\Domain\Platform\Services\AuditLogger;
use App\Support\Exceptions\BusinessRuleViolation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Territories, their representatives/supervisors, and customer assignments
 * (spec §12.5). Assignments drive what restricted members can see.
 */
class TerritoryService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenant,
        private readonly EntitlementService $entitlements,
    ) {}

    public function save(?Territory $territory, array $data): Territory
    {
        return DB::transaction(function () use ($territory, $data) {
            if (isset($data['parent_id']) && $territory !== null && $this->wouldCycle($territory->id, $data['parent_id'])) {
                throw new BusinessRuleViolation('territory_cycle', 'A territory cannot sit under itself.', 422);
            }
            $before = $territory?->getAttributes() ?? [];
            $territory ??= new Territory;
            $territory->fill($data)->save();
            $this->audit->record($before === [] ? 'territory.created' : 'territory.updated', $territory, $before, $data);

            return $territory;
        });
    }

    /** @param list<array{user_id: int, role: string}> $members Replaces the member list. */
    public function setMembers(Territory $territory, array $members): Territory
    {
        return DB::transaction(function () use ($territory, $members) {
            $userIds = array_unique(array_column($members, 'user_id'));
            $active = OrganizationMembership::query()->where('organization_id', $territory->organization_id)->where('status', 'active')
                ->whereIn('user_id', $userIds)->pluck('user_id')->all();
            if ($missing = array_values(array_diff($userIds, $active))) {
                throw new BusinessRuleViolation('territory_member_invalid', 'Members must be active members of the organization.', 422, ['user_ids' => $missing]);
            }

            // The plan's mobile_users limit counts distinct members across all territories.
            $organization = $this->tenant->organization();
            $elsewhere = TerritoryMember::query()->where('territory_id', '!=', $territory->id)->distinct()->pluck('user_id');
            $adding = max(0, $elsewhere->merge($userIds)->unique()->count() - ($this->entitlements->usage($organization)['mobile_users'] ?? 0));

            $before = $territory->members()->get(['user_id', 'role'])->toArray();
            $replace = function () use ($territory, $members) {
                $territory->members()->delete();
                foreach ($members as $m) {
                    TerritoryMember::query()->firstOrCreate(['territory_id' => $territory->id, 'user_id' => $m['user_id'], 'role' => $m['role']]);
                }
            };
            // Removing or reshuffling members never needs a free slot.
            $adding > 0 ? $this->entitlements->guard($organization, 'mobile_users', $replace, $adding) : $replace();
            $this->audit->record('territory.members_replaced', $territory, ['members' => $before], ['members' => $members]);

            return $territory->load('members');
        });
    }

    /**
     * @param  list<string>  $customerIds
     */
    public function assignCustomers(?Territory $territory, array $customerIds, ?int $representativeId, bool $setRepresentative): int
    {
        return DB::transaction(function () use ($territory, $customerIds, $representativeId, $setRepresentative) {
            if ($representativeId !== null && ! OrganizationMembership::query()->where('user_id', $representativeId)->where('status', 'active')
                ->where('organization_id', $this->tenant->id())->exists()) {
                throw new BusinessRuleViolation('representative_invalid', 'The representative must be an active member of the organization.', 422);
            }

            $customers = Customer::query()->whereIn('id', $customerIds)->lockForUpdate()->get();
            if ($customers->count() !== count(array_unique($customerIds))) {
                throw new BusinessRuleViolation('customer_not_found', 'One or more customers were not found.', 404);
            }

            foreach ($customers as $customer) {
                $before = ['territory_id' => $customer->territory_id, 'assigned_representative_id' => $customer->assigned_representative_id];
                $after = ['territory_id' => $territory?->id] + ($setRepresentative ? ['assigned_representative_id' => $representativeId] : []);
                $customer->forceFill($after)->save();
                $this->audit->record('customer.assignment_changed', $customer, $before, $after);
            }

            return $customers->count();
        });
    }

    private function wouldCycle(string $territoryId, string $parentId): bool
    {
        for ($id = $parentId, $guard = 0; $id !== null && $guard < 50; $guard++) {
            if ($id === $territoryId) {
                return true;
            }
            $id = Territory::query()->whereKey($id)->value('parent_id');
        }

        return false;
    }
}

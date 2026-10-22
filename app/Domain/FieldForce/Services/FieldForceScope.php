<?php

namespace App\Domain\FieldForce\Services;

use App\Domain\FieldForce\Models\TerritoryMember;
use App\Domain\Parties\Models\Customer;
use App\Support\Exceptions\BusinessRuleViolation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Who can see which customers and whose field activity (spec §12.5:
 * "do not expose one representative's restricted customer information to
 * another representative without authorization").
 *
 *   customers.view           → every customer of the organization
 *   customers.view_assigned  → customers assigned to me, or in a territory where I am a member
 *   field_force.view_all     → everyone's visits, orders and follow-ups
 *   field_force.team.view    → mine plus the representatives of territories I supervise
 *   otherwise                → my own records only
 *
 * Nothing is memoized: a scoped instance may outlive a request (tests, Octane),
 * and assignments must take effect immediately.
 */
class FieldForceScope
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function seesAllCustomers(): bool
    {
        return Gate::allows('customers.view');
    }

    public function canSeeCustomers(): bool
    {
        return $this->seesAllCustomers() || Gate::allows('customers.view_assigned');
    }

    /** Restrict a query with a customer id column to the visible customers. */
    public function scopeCustomers($query, string $column = 'customer_id')
    {
        if ($this->seesAllCustomers()) {
            return $query;
        }
        if (! Gate::allows('customers.view_assigned')) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn($column, $this->visibleCustomerIds());
    }

    public function customerIsVisible(string $customerId): bool
    {
        return $this->scopeCustomers(Customer::query()->whereKey($customerId), 'id')->exists();
    }

    /** 404 rather than 403: a hidden customer's existence is not disclosed. */
    public function visibleCustomer(string $customerId): Customer
    {
        $customer = $this->scopeCustomers(Customer::query()->whereKey($customerId), 'id')->first();
        if ($customer === null) {
            throw new BusinessRuleViolation('customer_not_found', 'Customer not found.', 404);
        }

        return $customer;
    }

    /** Subquery of customer ids visible to a restricted member. */
    public function visibleCustomerIds()
    {
        $territories = $this->territoryIds();

        return DB::table('customers')->where('organization_id', $this->tenant->id())
            ->where(fn ($q) => $q->where('assigned_representative_id', Auth::id())
                ->when($territories !== [], fn ($w) => $w->orWhereIn('territory_id', $territories)))
            ->select('id');
    }

    /** Territories where the current user is a representative or supervisor. */
    public function territoryIds(): array
    {
        return TerritoryMember::query()->where('user_id', Auth::id())->distinct()->pluck('territory_id')->all();
    }

    public function supervisedTerritoryIds(): array
    {
        return TerritoryMember::query()->where('user_id', Auth::id())->where('role', 'supervisor')->pluck('territory_id')->all();
    }

    /**
     * User ids whose field activity the current member may see; null means everyone.
     *
     * @return list<int>|null
     */
    public function visibleRepresentativeIds(): ?array
    {
        if (Gate::allows('field_force.view_all')) {
            return null;
        }

        $ids = [(int) Auth::id()];
        if (Gate::allows('field_force.team.view') && ($supervised = $this->supervisedTerritoryIds()) !== []) {
            $ids = array_merge($ids, TerritoryMember::query()->whereIn('territory_id', $supervised)->pluck('user_id')->map(fn ($id) => (int) $id)->all());
        }

        return array_values(array_unique($ids));
    }

    /** Restrict a query with a representative column to the visible team. */
    public function scopeRepresentatives($query, string $column = 'representative_id')
    {
        $ids = $this->visibleRepresentativeIds();

        return $ids === null ? $query : $query->whereIn($column, $ids);
    }

    /**
     * Field activity (visits, orders, follow-ups, interactions, detailing…) the current member may see:
     * their own records always; a team member's records only when the customer is also visible to
     * the viewer. A supervisor of Lagos Island therefore sees a representative's Lagos Island visits,
     * but not that same representative's visits to customers of a territory the supervisor does not
     * cover (spec Phase 6: "without violating tenant or territory permissions").
     */
    public function scopeActivity($query, string $representativeColumn = 'representative_id', string $customerColumn = 'customer_id')
    {
        $team = $this->visibleRepresentativeIds();
        if ($team === null) {
            return $query; // field_force.view_all
        }

        return $query->where(fn ($q) => $q->where($representativeColumn, Auth::id())
            ->orWhere(fn ($w) => $this->scopeCustomers($w->whereIn($representativeColumn, $team), $customerColumn)));
    }

    public function canSeeRepresentative(int $userId): bool
    {
        $ids = $this->visibleRepresentativeIds();

        return $ids === null || in_array($userId, $ids, true);
    }
}

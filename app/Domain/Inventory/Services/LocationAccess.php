<?php

namespace App\Domain\Inventory\Services;

use App\Domain\Organizations\Models\Warehouse;
use App\Support\Exceptions\BusinessRuleViolation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Branch/warehouse-level access (spec §5.3). A membership with
 * all_locations = false sees only the warehouses it is scoped to, directly
 * or through their branch.
 */
class LocationAccess
{
    /** @var array<string, list<string>> */
    private array $cache = [];

    public function __construct(private readonly TenantContext $tenant) {}

    /** @return list<string>|null null = unrestricted */
    public function warehouseIds(): ?array
    {
        $membership = $this->tenant->membership();
        if ($membership === null || $membership->all_locations) {
            return null;
        }

        // Keyed by membership: long-lived workers and the test runner reuse this instance.
        return $this->cache[$membership->id] ??= (function () use ($membership) {
            $scopes = DB::table('membership_location_scopes')->where('membership_id', $membership->id)->get();
            $direct = $scopes->where('location_type', 'warehouse')->pluck('location_id');
            $branches = $scopes->where('location_type', 'branch')->pluck('location_id');

            return Warehouse::query()
                ->where(fn ($q) => $q->whereIn('id', $direct)->orWhereIn('branch_id', $branches))
                ->pluck('id')->all();
        })();
    }

    public function canAccessWarehouse(string $warehouseId): bool
    {
        $ids = $this->warehouseIds();

        return $ids === null || in_array($warehouseId, $ids, true);
    }

    public function assertWarehouse(string ...$warehouseIds): void
    {
        foreach ($warehouseIds as $id) {
            if (! $this->canAccessWarehouse($id)) {
                throw new BusinessRuleViolation('location_access_denied', 'You do not have access to this warehouse.', 403, ['warehouse_id' => $id]);
            }
        }
    }

    /** Restrict a query on a warehouse_id column to accessible warehouses. */
    public function scope($query, string $column = 'warehouse_id')
    {
        $ids = $this->warehouseIds();

        return $ids === null ? $query : $query->whereIn($column, $ids);
    }
}

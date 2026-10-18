<?php

namespace App\Domain\FieldForce\Services;

use App\Domain\Catalogue\Services\UnitConverter;
use App\Domain\FieldForce\Models\Territory;
use App\Domain\Organizations\Models\Warehouse;
use App\Domain\Parties\Models\Customer;
use App\Domain\Sales\Services\SalesPricing;
use App\Support\Exceptions\BusinessRuleViolation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Product list for the field app (spec §12.4): the organization's active
 * catalogue with the price that applies to a given customer and the
 * sellable stock at the fulfilment warehouse, stamped with when it was read
 * so the device can show how old its cached copy is.
 */
class MobileCatalogue
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly SalesPricing $pricing,
        private readonly UnitConverter $units,
    ) {}

    public function forCustomer(Customer $customer, ?string $warehouseId, ?string $query, int $page = 1, int $perPage = 50): array
    {
        $warehouseId ??= $customer->territory_id ? Territory::query()->whereKey($customer->territory_id)->value('warehouse_id') : null;
        $warehouse = $warehouseId ? Warehouse::query()->findOrFail($warehouseId) : null;
        $today = today()->toDateString();
        $orgId = $this->tenant->id();

        $paginator = DB::table('organization_products as op')
            ->join('products as p', 'p.id', '=', 'op.product_id')
            ->where('op.organization_id', $orgId)->where('op.status', 'active')->where('p.status', 'active')
            ->when(filled($query), function ($q) use ($query) {
                $like = '%'.addcslashes(trim($query), '%_').'%';
                $q->where(fn ($w) => $w->where('p.display_name', 'like', $like)->orWhere('p.generic_name', 'like', $like)->orWhere('op.sku', trim($query)));
            })
            ->select(['p.id as product_id', 'p.display_name', 'p.generic_name', 'op.sku'])
            ->selectSub(fn ($s) => $s->from('inventory_balances as ib')->join('batches as bt', 'bt.id', '=', 'ib.batch_id')
                ->whereColumn('ib.product_id', 'p.id')->where('ib.organization_id', $orgId)->where('ib.stock_state', 'available')
                ->when($warehouse, fn ($w) => $w->where('ib.warehouse_id', $warehouse->id))
                ->where('bt.quality_status', 'released')->where('bt.recall_status', 'none')
                ->where(fn ($x) => $x->whereNull('bt.expiry_date')->orWhere('bt.expiry_date', '>=', $today))
                ->selectRaw('COALESCE(SUM(ib.quantity - ib.reserved_quantity), 0)'), 'sellable_base_units')
            ->orderBy('p.display_name')
            ->paginate($perPage, page: $page);

        $priceWarehouse = $warehouse ?? new Warehouse; // without a warehouse, branch price lists do not apply
        $items = collect($paginator->items())->map(function ($row) use ($customer, $priceWarehouse, $today) {
            $levels = $this->units->catalogueItem($row->product_id)->product->packageLevels->where('is_sellable', true)->sortBy('level')
                ->map(function ($level) use ($row, $customer, $priceWarehouse, $today) {
                    try {
                        $price = $this->pricing->listPrice($customer, $priceWarehouse, $row->product_id, $level->code, '1', $today);
                    } catch (BusinessRuleViolation) {
                        $price = null; // shown as "price on request"; the ERP prices the order on acceptance
                    }

                    return ['code' => $level->code, 'name' => $level->name, 'base_unit_quantity' => (string) $level->base_unit_quantity,
                        'indicative_unit_price' => $price['price'] ?? null, 'price_source' => $price['source'] ?? null];
                })->values();

            return [
                'product_id' => $row->product_id, 'display_name' => $row->display_name, 'generic_name' => $row->generic_name, 'sku' => $row->sku,
                'currency_code' => $customer->currency_code, 'package_levels' => $levels,
                'sellable_base_units' => bcadd((string) $row->sellable_base_units, '0', 4),
            ];
        });

        return [
            'data' => $items,
            'meta' => [
                'customer_id' => $customer->id, 'warehouse_id' => $warehouse?->id, 'stock_as_of' => now()->toIso8601String(),
                'current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'total' => $paginator->total(),
                'note' => 'Prices are indicative and stock is a snapshot; the order is priced and stock is reserved only when the ERP accepts it.',
            ],
        ];
    }
}

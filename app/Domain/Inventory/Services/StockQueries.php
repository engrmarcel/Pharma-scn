<?php

namespace App\Domain\Inventory\Services;

use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read-side stock queries shared by inventory and procurement endpoints.
 */
class StockQueries
{
    public function __construct(private readonly TenantContext $tenant) {}

    /** Catalogue items whose organization-wide sellable stock is at or below their reorder level (base units). */
    public function lowStock(): Collection
    {
        $today = today()->toDateString();

        return DB::table('organization_products as op')
            ->join('products as p', 'p.id', '=', 'op.product_id')
            ->leftJoin('inventory_balances as b', fn ($j) => $j->on('b.product_id', '=', 'op.product_id')->on('b.organization_id', '=', 'op.organization_id')->where('b.stock_state', 'available'))
            ->leftJoin('batches as bt', 'bt.id', '=', 'b.batch_id')
            ->where('op.organization_id', $this->tenant->id())
            ->whereNotNull('op.reorder_level')
            ->groupBy('op.id', 'op.product_id', 'op.sku', 'p.display_name', 'op.reorder_level', 'op.reorder_quantity', 'op.preferred_supplier_id')
            ->selectRaw("op.product_id, op.sku, p.display_name as product_name, op.reorder_level, op.reorder_quantity, op.preferred_supplier_id,
                COALESCE(SUM(CASE WHEN bt.quality_status = 'released' AND bt.recall_status = 'none' AND (bt.expiry_date IS NULL OR bt.expiry_date >= ?) THEN b.quantity - b.reserved_quantity END), 0) as sellable", [$today])
            ->havingRaw('sellable <= op.reorder_level')
            ->orderBy('p.display_name')
            ->get();
    }
}

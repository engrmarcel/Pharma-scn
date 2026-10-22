<?php

namespace App\Domain\Pos\Services;

use App\Domain\Pos\Models\PosTerminal;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Till lookup: name, SKU, GTIN or batch number, with sellable stock at the
 * terminal's warehouse. Exact code matches (scanner input) are flagged so the
 * register can add them without a second keystroke.
 */
class PosCatalogue
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function lookup(PosTerminal $terminal, string $query, int $limit = 25): Collection
    {
        $q = trim($query);
        $today = business_today()->toDateString();
        $like = '%'.addcslashes($q, '%_').'%';
        $orgId = $this->tenant->id();

        return DB::table('organization_products as op')
            ->join('products as p', 'p.id', '=', 'op.product_id')
            ->where('op.organization_id', $orgId)->where('op.status', 'active')
            ->where(fn ($w) => $w->where('p.display_name', 'like', $like)->orWhere('p.generic_name', 'like', $like)->orWhere('op.sku', $q)
                ->orWhereExists(fn ($e) => $e->from('product_identifiers as pi')->whereColumn('pi.product_id', 'p.id')->where('pi.value', $q))
                ->orWhereExists(fn ($e) => $e->from('batches as b')->whereColumn('b.product_id', 'p.id')->where('b.organization_id', $orgId)->where('b.batch_number', strtoupper($q))))
            ->select(['p.id as product_id', 'p.display_name', 'op.sku'])
            ->selectRaw('(op.sku = ? OR EXISTS (SELECT 1 FROM product_identifiers pi2 WHERE pi2.product_id = p.id AND pi2.value = ?)) as exact_code', [$q, $q])
            ->selectSub(fn ($s) => $s->from('batches as bx')->where('bx.organization_id', $orgId)->whereColumn('bx.product_id', 'p.id')->where('bx.batch_number', strtoupper($q))->select('bx.id')->limit(1), 'scanned_batch_id')
            ->selectSub(fn ($s) => $s->from('inventory_balances as ib')->join('batches as bt', 'bt.id', '=', 'ib.batch_id')
                ->whereColumn('ib.product_id', 'p.id')->where('ib.warehouse_id', $terminal->warehouse_id)->where('ib.stock_state', 'available')
                ->where('bt.quality_status', 'released')->where('bt.recall_status', 'none')
                ->where(fn ($x) => $x->whereNull('bt.expiry_date')->orWhere('bt.expiry_date', '>=', $today))
                ->selectRaw('COALESCE(SUM(ib.quantity - ib.reserved_quantity), 0)'), 'sellable_base_units')
            ->orderByDesc('exact_code')->orderBy('p.display_name')
            ->limit($limit)->get();
    }
}

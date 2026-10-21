<?php

namespace App\Domain\Pos\Services;

use App\Domain\Organizations\Models\Warehouse;
use App\Domain\Pos\Models\PosTerminal;
use App\Domain\Sales\Services\SalesPolicy;
use App\Domain\Sales\Services\SalesPricing;
use App\Support\Exceptions\BusinessRuleViolation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * What a till needs to keep selling without a connection (spec §13.1, §13.6
 * "minimum data"): for each active product, its barcodes, prescription
 * classification, today's tax rate, the list price of every sellable pack
 * and the sellable batches at the terminal's warehouse, stamped with the time
 * the snapshot was taken. The device sells from this cache; the server
 * re-checks everything when the sale syncs.
 */
class PosOfflineCatalogue
{
    public const MAX_PRODUCTS = 5000;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly SalesPricing $pricing,
        private readonly SalesPolicy $policy,
        private readonly PrescriptionGuard $prescriptions,
    ) {}

    public function snapshot(PosTerminal $terminal): array
    {
        $org = $this->tenant->organization();
        $warehouse = Warehouse::query()->findOrFail($terminal->warehouse_id);
        $today = today()->toDateString();

        $products = DB::table('organization_products as op')->join('products as p', 'p.id', '=', 'op.product_id')
            ->where('op.organization_id', $org->id)->where('op.status', 'active')->where('p.status', 'active')
            ->orderBy('p.display_name')->limit(self::MAX_PRODUCTS + 1)
            ->get(['p.id', 'p.display_name', 'p.generic_name', 'op.sku']);
        if ($products->count() > self::MAX_PRODUCTS) {
            throw new BusinessRuleViolation('catalogue_too_large', 'The catalogue is larger than the offline limit ('.self::MAX_PRODUCTS.' products).', 422);
        }
        $ids = $products->pluck('id');

        $levels = DB::table('product_package_levels')->whereIn('product_id', $ids)->where('is_sellable', true)->orderBy('level')
            ->get(['product_id', 'code', 'name', 'base_unit_quantity'])->groupBy('product_id');
        $barcodes = DB::table('product_identifiers')->whereIn('product_id', $ids)->get(['product_id', 'value'])->groupBy('product_id');
        $batches = DB::table('inventory_balances as b')->join('batches as bt', 'bt.id', '=', 'b.batch_id')
            ->where('b.organization_id', $org->id)->where('b.warehouse_id', $warehouse->id)->where('b.stock_state', 'available')
            ->whereRaw('b.quantity - b.reserved_quantity > 0')
            ->where('bt.quality_status', 'released')->where('bt.recall_status', 'none')
            ->where(fn ($q) => $q->whereNull('bt.expiry_date')->orWhere('bt.expiry_date', '>=', $today))
            ->orderByRaw('bt.expiry_date IS NULL')->orderBy('bt.expiry_date')
            ->get(['b.product_id', 'bt.id', 'bt.batch_number', 'bt.expiry_date', DB::raw('b.quantity - b.reserved_quantity as free')])->groupBy('product_id');

        $items = $products->map(function ($p) use ($levels, $barcodes, $batches, $warehouse, $today) {
            try {
                $tax = $this->pricing->tax($p->id, $today);
            } catch (BusinessRuleViolation) {
                $tax = null; // no tax configured: the product cannot be priced, so it is not sellable offline
            }

            return [
                'product_id' => $p->id,
                'display_name' => $p->display_name,
                'generic_name' => $p->generic_name,
                'sku' => $p->sku,
                'barcodes' => $barcodes->get($p->id, collect())->pluck('value')->values(),
                'classification' => $this->prescriptions->classification($p->id),
                'tax_category_code' => $tax['category'] ?? null,
                'tax_rate' => $tax['rate'] ?? null,
                'package_levels' => $levels->get($p->id, collect())->map(function ($l) use ($p, $warehouse, $today) {
                    try {
                        $price = $this->pricing->listPrice(null, $warehouse, $p->id, $l->code, '1', $today)['price'];
                    } catch (BusinessRuleViolation) {
                        $price = null;
                    }

                    return ['code' => $l->code, 'name' => $l->name, 'base_unit_quantity' => (string) $l->base_unit_quantity, 'unit_price' => $price];
                })->values(),
                'batches' => $batches->get($p->id, collect())->map(fn ($b) => [
                    'batch_id' => $b->id, 'batch_number' => $b->batch_number, 'expiry_date' => $b->expiry_date, 'free_base_units' => bcadd((string) $b->free, '0', 4),
                ])->values(),
            ];
        })->values();

        return [
            'terminal' => $terminal->only(['id', 'code', 'name', 'warehouse_id']),
            'currency_code' => $org->country->currency_code,
            'settings' => [
                'max_discount_percent' => $this->policy->settings()['max_discount_percent'],
                'enforce_prescription_controls' => (bool) data_get($org->settings, 'pos.enforce_prescription_controls', true),
            ],
            'items' => $items,
            'as_of' => now()->toIso8601String(),
            'note' => 'Offline prices, stock and tax are as of this snapshot. The server re-checks every sale when it syncs.',
        ];
    }
}

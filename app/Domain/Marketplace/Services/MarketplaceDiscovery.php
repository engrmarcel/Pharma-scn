<?php

namespace App\Domain\Marketplace\Services;

use App\Domain\Organizations\Models\Organization;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Supplier and product discovery (spec §14.1). Every result says where its
 * availability comes from and how fresh it is:
 *
 *   live      read from the supplier's ERP stock at the moment of the search, shown
 *             as a status band (not an exact count) and never reserved by being shown;
 *   declared  the supplier's own statement with its date, flagged stale after N days.
 *
 * Restricted products are shown with a clear label and whether this buyer is eligible.
 */
class MarketplaceDiscovery
{
    public function __construct(private readonly MarketplaceAccess $access) {}

    public function search(Organization $buyer, array $f, int $page = 1, int $perPage = 25): array
    {
        $today = business_today()->toDateString();
        $live = DB::table('inventory_balances as ib')->join('batches as bt', 'bt.id', '=', 'ib.batch_id')
            ->whereColumn('ib.organization_id', 'l.organization_id')->whereColumn('ib.product_id', 'l.product_id')
            ->where('ib.stock_state', 'available')->where('bt.quality_status', 'released')->where('bt.recall_status', 'none')
            ->where(fn ($q) => $q->whereNull('bt.expiry_date')->orWhere('bt.expiry_date', '>=', $today))
            ->where(fn ($q) => $q->whereNull('l.warehouse_id')->orWhereColumn('ib.warehouse_id', 'l.warehouse_id'))
            ->selectRaw('COALESCE(SUM(ib.quantity - ib.reserved_quantity), 0)');

        $query = DB::table('marketplace_listings as l')
            ->join('organizations as o', 'o.id', '=', 'l.organization_id')
            ->join('marketplace_profiles as mp', fn ($j) => $j->on('mp.organization_id', '=', 'o.id')->where('mp.listed', true))
            ->join('organization_modules as om', fn ($j) => $j->on('om.organization_id', '=', 'o.id')->where('om.module', 'marketplace')->where('om.enabled', true))
            ->join('products as p', 'p.id', '=', 'l.product_id')
            ->join('product_package_levels as pl', fn ($j) => $j->on('pl.product_id', '=', 'l.product_id')->on('pl.code', '=', 'l.package_level_code'))
            ->leftJoin('brands as br', 'br.id', '=', 'p.brand_id')
            ->where('l.status', 'active')->where('o.status', 'active')->where('p.status', 'active')
            ->where('l.organization_id', '!=', $buyer->id)
            ->whereIn('o.type', array_keys(config('marketplace.buyer_types_by_supplier_type')))
            ->when($f['q'] ?? null, function ($q, $v) {
                $like = '%'.addcslashes($v, '%_').'%';
                $q->where(fn ($w) => $w->where('p.display_name', 'like', $like)->orWhere('p.generic_name', 'like', $like)->orWhere('br.name', 'like', $like));
            })
            ->when($f['ingredient'] ?? null, fn ($q, $v) => $q->whereExists(fn ($e) => $e->from('product_ingredients as pi')->join('ingredients as i', 'i.id', '=', 'pi.ingredient_id')
                ->whereColumn('pi.product_id', 'p.id')->where('i.name', 'like', '%'.addcslashes($v, '%_').'%')))
            ->when($f['strength'] ?? null, fn ($q, $v) => $q->where('p.strength_text', 'like', '%'.addcslashes($v, '%_').'%'))
            ->when($f['dosage_form'] ?? null, fn ($q, $v) => $q->where('p.dosage_form_code', $v))
            ->when($f['pack'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('l.package_level_code', $v)->orWhere('pl.name', 'like', '%'.addcslashes($v, '%_').'%')))
            ->when($f['product_id'] ?? null, fn ($q, $v) => $q->where('l.product_id', $v))
            ->when($f['supplier'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('o.id', $v)->orWhere('o.name', 'like', '%'.addcslashes($v, '%_').'%')))
            ->when($f['supplier_type'] ?? null, fn ($q, $v) => $q->where('o.type', $v))
            ->when($f['country'] ?? null, fn ($q, $v) => $q->where('o.country_code', strtoupper($v)))
            ->when($f['state'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('o.state_region', $v)->orWhereRaw('JSON_CONTAINS(mp.regions_served, ?)', [json_encode($v)])))
            ->when($f['delivery'] ?? null, fn ($q, $v) => $q->whereRaw('JSON_CONTAINS(mp.delivery_options, ?)', [json_encode($v)]))
            ->when(! empty($f['verified_only']), fn ($q) => $q->where('o.verification_status', 'verified'))
            ->when(! empty($f['available_only']), fn ($q) => $q->where(fn ($w) => $w
                ->where(fn ($d) => $d->where('l.availability_mode', 'declared')->whereIn('l.declared_availability', ['in_stock', 'limited']))
                ->orWhere(fn ($d) => $d->where('l.availability_mode', 'live')->whereRaw('('.$live->toSql().') >= l.minimum_order_quantity * pl.base_unit_quantity', $live->getBindings()))))
            ->select(['l.id', 'l.organization_id', 'l.product_id', 'l.package_level_code', 'l.unit_price', 'l.currency_code', 'l.minimum_order_quantity', 'l.availability_mode',
                'l.declared_availability', 'l.declared_at', 'l.lead_time_days', 'l.allowed_buyer_types',
                'p.display_name', 'p.generic_name', 'p.strength_text', 'p.dosage_form_code', 'p.platform_sku', 'br.name as brand_name',
                'pl.name as level_name', 'pl.base_unit_quantity', 'mp.delivery_options', 'mp.regions_served', 'mp.minimum_order_value'])
            ->selectSub($live, 'live_units')
            ->orderBy('p.display_name')->orderBy('l.unit_price');

        $paginator = $query->paginate($perPage, page: $page);
        $suppliers = Organization::query()->whereIn('id', collect($paginator->items())->pluck('organization_id')->unique())->get()->keyBy('id');
        $relationships = DB::table('trading_relationships')->where('buyer_organization_id', $buyer->id)
            ->whereIn('supplier_organization_id', $suppliers->keys())->pluck('status', 'supplier_organization_id');
        $staleDays = config('marketplace.declared_availability_stale_after_days');

        $rows = collect($paginator->items())
            ->filter(fn ($r) => $this->access->participates($suppliers[$r->organization_id])) // entitlement, not just the module flag
            ->map(function ($r) use ($buyer, $suppliers, $relationships, $staleDays) {
                $supplier = $suppliers[$r->organization_id];
                $allowedTypes = $r->allowed_buyer_types ? json_decode($r->allowed_buyer_types, true) : null;
                $classification = $this->access->classification($r->product_id, $supplier->country_code);
                $block = $this->access->productBlock($buyer, $supplier, $r->product_id, $allowedTypes, $classification);

                return [
                    'listing_id' => $r->id,
                    'product' => ['id' => $r->product_id, 'display_name' => $r->display_name, 'generic_name' => $r->generic_name, 'brand' => $r->brand_name,
                        'strength' => $r->strength_text, 'dosage_form' => $r->dosage_form_code, 'platform_sku' => $r->platform_sku],
                    'pack' => ['code' => $r->package_level_code, 'name' => $r->level_name, 'base_unit_quantity' => (string) $r->base_unit_quantity],
                    'unit_price' => (string) $r->unit_price, 'currency_code' => $r->currency_code,
                    'minimum_order_quantity' => (string) $r->minimum_order_quantity, 'lead_time_days' => $r->lead_time_days,
                    'availability' => $this->availability($r, $staleDays),
                    'supplier' => ['id' => $supplier->id, 'name' => $supplier->name, 'type' => $supplier->type->value, 'country_code' => $supplier->country_code,
                        'state_region' => $supplier->state_region, 'verification' => MarketplaceAccess::verificationLabel($supplier),
                        'delivery_options' => json_decode($r->delivery_options ?? '[]', true), 'regions_served' => json_decode($r->regions_served ?? '[]', true),
                        'minimum_order_value' => $r->minimum_order_value],
                    'restriction' => ['classification' => $classification, 'restricted' => $this->access->isRestricted($classification),
                        'you_may_buy' => $block === null, 'reason' => $block['message'] ?? null],
                    'relationship' => $relationships[$r->organization_id] ?? null,
                ];
            })->values();

        return ['data' => $rows, 'meta' => ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'searched_at' => now()->toIso8601String()]];
    }

    private function availability(object $r, int $staleDays): array
    {
        if ($r->availability_mode === 'declared') {
            $declaredAt = $r->declared_at ? Carbon::parse($r->declared_at) : null;

            return [
                'source' => 'declared', 'status' => $r->declared_availability, 'declared_at' => $declaredAt?->toIso8601String(),
                'stale' => $declaredAt === null || $declaredAt->lt(now()->subDays($staleDays)),
                'note' => 'Stated by the supplier on the date shown; not checked against stock.',
            ];
        }

        $minimumBase = bcmul((string) $r->minimum_order_quantity, (string) $r->base_unit_quantity, 4);
        $units = (string) $r->live_units;
        $status = bccomp($units, $minimumBase, 4) < 0 ? 'out_of_stock' : (bccomp($units, bcmul($minimumBase, '5', 4), 4) < 0 ? 'limited' : 'in_stock');

        return [
            'source' => 'live', 'status' => $status, 'as_of' => now()->toIso8601String(),
            'note' => 'From the supplier\'s stock records at the time of this search. Not reserved until the supplier accepts an order.',
        ];
    }
}

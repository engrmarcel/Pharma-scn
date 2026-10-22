<?php

namespace App\Domain\Analytics\Services;

use App\Domain\Analytics\Support\AnalyticsDb;
use App\Domain\Analytics\Support\Figures;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Inventory, stock movement and expiry-risk analytics (spec §19.1). Quantities are
 * base units. Stock value needs inventory.valuation.view and is otherwise withheld.
 * Days of cover and unsold-before-expiry are estimates from recent demand and are
 * labelled as such.
 */
class InventoryAnalytics
{
    public function __construct(private readonly AnalyticsScope $scope, private readonly DemandFacts $demand) {}

    public function inventory(string $from, string $to, ?string $warehouseId = null): array
    {
        $this->scope->authorize('inventory.view');
        [$start, $end] = $this->scope->period($from, $to);
        $org = $this->scope->organizationId();
        $warehouses = $this->scope->warehouses($warehouseId);
        $showValue = $this->scope->can('inventory.valuation.view');

        $balances = $this->scope->scope(AnalyticsDb::table('inventory_balances as b')->join('batches as bt', 'bt.id', '=', 'b.batch_id')
            ->where('b.organization_id', $org)->where('b.quantity', '>', 0), 'b.warehouse_id', $warehouses)
            ->groupBy('b.stock_state', 'bt.currency_code')
            ->selectRaw('b.stock_state, bt.currency_code, SUM(b.quantity) as quantity, SUM(b.reserved_quantity) as reserved,
                SUM(CASE WHEN bt.unit_cost IS NOT NULL THEN b.quantity * bt.unit_cost ELSE 0 END) as amount,
                SUM(CASE WHEN bt.unit_cost IS NULL THEN b.quantity ELSE 0 END) as uncosted, COUNT(DISTINCT b.product_id) as products')
            ->get();

        $movements = $this->scope->scope(AnalyticsDb::table('inventory_movements')->where('organization_id', $org)
            ->whereBetween('posted_at', [$start, $end]), 'warehouse_id', $warehouses)
            ->groupBy('movement_type')
            ->selectRaw('movement_type, COUNT(*) as movements, SUM(CASE WHEN quantity > 0 THEN quantity ELSE 0 END) as quantity_in,
                SUM(CASE WHEN quantity < 0 THEN -quantity ELSE 0 END) as quantity_out')
            ->orderBy('movement_type')->get()
            ->map(fn ($r) => ['movement_type' => $r->movement_type, 'movements' => (int) $r->movements,
                'quantity_in' => Figures::dec($r->quantity_in), 'quantity_out' => Figures::dec($r->quantity_out)]);

        $cover = $this->cover($org, $warehouses);

        return [
            'from' => $from, 'to' => $to, 'warehouse_id' => $warehouseId, 'locations' => $warehouses === null ? 'all' : count($warehouses),
            'stock_by_state' => $balances->groupBy('stock_state')->map(fn ($rows, $state) => ['stock_state' => $state,
                'quantity' => $rows->reduce(fn ($c, $r) => bcadd($c, Figures::dec($r->quantity), 4), '0'),
                'reserved' => $rows->reduce(fn ($c, $r) => bcadd($c, Figures::dec($r->reserved), 4), '0'),
            ])->values(),
            'products_in_stock' => (int) $this->scope->scope(AnalyticsDb::table('inventory_balances')->where('organization_id', $org)->where('quantity', '>', 0), 'warehouse_id', $warehouses)
                ->distinct()->count('product_id'),
            'stock_value' => $showValue ? ['by_currency' => Figures::money($balances), 'uncosted_quantity' => $balances->reduce(fn ($c, $r) => bcadd($c, Figures::dec($r->uncosted), 4), '0'),
                'basis' => 'Batch cost (specific identification), all stock states.'] : Figures::withheld('Needs inventory.valuation.view.'),
            'movements' => $movements,
            'below_reorder_level' => $cover->filter(fn ($p) => $p['reorder_level'] !== null && bccomp($p['sellable'], $p['reorder_level'], 4) <= 0)->count(),
            'stockout_risk' => $cover->filter(fn ($p) => $p['days_of_cover'] !== null && $p['days_of_cover'] < config('analytics.cover_warning_days', 14))
                ->sortBy('days_of_cover')->take(20)->values(),
            'slow_moving' => $cover->filter(fn ($p) => bccomp($p['average_daily_demand'], '0', 4) === 0 && bccomp($p['sellable'], '0', 4) > 0)
                ->sortByDesc(fn ($p) => (float) $p['sellable'])->take(20)->values()
                ->map(fn ($p) => ['product_id' => $p['product_id'], 'sku' => $p['sku'], 'product_name' => $p['product_name'], 'sellable' => $p['sellable']]),
            'estimates' => ['kind' => 'estimate', 'basis' => 'Days of cover = sellable stock ÷ average net daily demand over the last '.config('analytics.demand_window_days').' days.',
                'demand_data' => $this->demand->lastRefresh($org)],
        ];
    }

    /**
     * Expiry risk: stock by time to expiry, and an estimate of what will not be sold before it
     * expires, consuming demand first-expiry-first-out across the product's batches.
     */
    public function expiryRisk(?string $warehouseId = null): array
    {
        $this->scope->authorize('inventory.view');
        $org = $this->scope->organizationId();
        $warehouses = $this->scope->warehouses($warehouseId);
        $showValue = $this->scope->can('inventory.valuation.view');
        $buckets = config('analytics.expiry_buckets');
        $horizon = end($buckets);
        $today = business_today();

        $rows = $this->expiryRows($org, $warehouses, $horizon);

        $label = function (int $days) use ($buckets) {
            if ($days < 0) {
                return 'expired';
            }
            $low = 0;
            foreach ($buckets as $b) {
                if ($days <= $b) {
                    return "{$low}-{$b}_days";
                }
                $low = $b + 1;
            }
        };
        $grouped = [];
        foreach ($rows as $r) {
            $key = $label((int) $today->diffInDays(Carbon::parse($r->expiry_date), false));
            $grouped[$key] ??= ['bucket' => $key, 'batches' => 0, 'quantity' => '0', 'value' => []];
            $grouped[$key]['batches']++;
            $grouped[$key]['quantity'] = bcadd($grouped[$key]['quantity'], Figures::dec($r->quantity), 4);
            if ($r->unit_cost !== null) {
                $grouped[$key]['value'][$r->currency_code] = bcadd($grouped[$key]['value'][$r->currency_code] ?? '0', bcmul(Figures::dec($r->quantity), (string) $r->unit_cost, 4), 4);
            }
        }
        $order = array_merge(['expired'], array_map(fn ($i) => ($i === 0 ? 0 : $buckets[$i - 1] + 1).'-'.$buckets[$i].'_days', array_keys($buckets)));
        $summary = collect($order)->filter(fn ($k) => isset($grouped[$k]))->map(function ($k) use ($grouped, $showValue) {
            $g = $grouped[$k];
            $g['value'] = $showValue ? collect($g['value'])->map(fn ($a, $c) => ['currency_code' => $c, 'amount' => $a])->values()->all() : Figures::withheld('Needs inventory.valuation.view.');

            return $g;
        })->values();

        $atRisk = $this->expiryAtRisk($org, $warehouses, $showValue, $rows);

        return [
            'as_of' => $today->toDateString(), 'warehouse_id' => $warehouseId,
            'buckets' => $summary,
            'at_risk' => $atRisk->take(50),
            'at_risk_batches' => $atRisk->count(),
            'estimates' => ['kind' => 'prediction', 'basis' => 'Average net daily demand over the last '.config('analytics.demand_window_days').' days, consumed first-expiry-first-out across each product\'s batches. Available stock only.',
                'demand_data' => $this->demand->lastRefresh($org)],
        ];
    }

    /**
     * Batches in scope expiring within $horizon days (or already expired), with quantity in all
     * states and available quantity.
     */
    private function expiryRows(string $org, ?array $warehouses, int $horizon): Collection
    {
        return $this->scope->scope(AnalyticsDb::table('inventory_balances as b')->join('batches as bt', 'bt.id', '=', 'b.batch_id')
            ->join('products as p', 'p.id', '=', 'b.product_id')
            ->leftJoin('organization_products as op', fn ($j) => $j->on('op.product_id', '=', 'b.product_id')->on('op.organization_id', '=', 'b.organization_id'))
            ->where('b.organization_id', $org)->where('b.quantity', '>', 0)->whereNotNull('bt.expiry_date')
            ->where('bt.expiry_date', '<=', business_today()->addDays($horizon)->toDateString()), 'b.warehouse_id', $warehouses)
            ->groupBy('b.product_id', 'op.sku', 'p.display_name', 'b.batch_id', 'bt.batch_number', 'bt.expiry_date', 'bt.unit_cost', 'bt.currency_code')
            ->selectRaw("b.product_id, op.sku, p.display_name as product_name, b.batch_id, bt.batch_number, bt.expiry_date, bt.unit_cost, bt.currency_code,
                SUM(b.quantity) as quantity, SUM(CASE WHEN b.stock_state = 'available' THEN b.quantity ELSE 0 END) as available")
            ->orderBy('bt.expiry_date')->get();
    }

    /**
     * Batches likely to expire unsold: average daily demand, consumed first-expiry-first-out
     * across each product's batches. Also used by the recommendation engine (no viewer checks).
     *
     * @return Collection<int, array>
     */
    public function expiryAtRisk(string $org, ?array $warehouses, bool $showValue, ?Collection $rows = null): Collection
    {
        $rows ??= $this->expiryRows($org, $warehouses, (int) collect(config('analytics.expiry_buckets'))->last());
        $today = business_today();
        $average = $this->demand->averageDaily($org, $warehouses, config('analytics.demand_window_days'));

        return $rows->filter(fn ($r) => Carbon::parse($r->expiry_date)->gte($today))->groupBy('product_id')
            ->flatMap(function (Collection $batches, $productId) use ($average, $today, $showValue) {
                $daily = $average[$productId] ?? '0';
                $consumedBefore = '0';

                return $batches->sortBy('expiry_date')->map(function ($r) use ($daily, $today, &$consumedBefore, $showValue) {
                    $days = (int) $today->diffInDays(Carbon::parse($r->expiry_date));
                    $sellable = bcsub(bcmul($daily, (string) $days, 4), $consumedBefore, 4);
                    $sellable = bccomp($sellable, '0', 4) > 0 ? $sellable : '0';
                    $available = Figures::dec($r->available);
                    $sold = bccomp($sellable, $available, 4) < 0 ? $sellable : $available;
                    $consumedBefore = bcadd($consumedBefore, $available, 4);
                    $unsold = bcsub($available, $sold, 4);

                    return ['product_id' => $r->product_id, 'sku' => $r->sku, 'product_name' => $r->product_name, 'batch_id' => $r->batch_id,
                        'batch_number' => $r->batch_number, 'expiry_date' => $r->expiry_date, 'days_to_expiry' => $days, 'available' => $available,
                        'average_daily_demand' => $daily, 'estimated_unsold' => $unsold,
                        'estimated_unsold_value' => $showValue && $r->unit_cost !== null ? ['currency_code' => $r->currency_code, 'amount' => bcmul($unsold, (string) $r->unit_cost, 4)] : null];
                });
            })->filter(fn ($l) => bccomp($l['estimated_unsold'], '0', 4) > 0)->sortBy('expiry_date')->values();
    }

    /**
     * Per product in scope: sellable stock, reorder settings, average daily demand and days of cover.
     *
     * @return Collection<int, array>
     */
    public function cover(string $org, ?array $warehouses): Collection
    {
        $today = business_today()->toDateString();
        // The location filter sits in the join, so products with no stock in scope still appear (with zero).
        $stock = AnalyticsDb::table('organization_products as op')->join('products as p', 'p.id', '=', 'op.product_id')
            ->leftJoin('inventory_balances as b', fn ($j) => $j->on('b.product_id', '=', 'op.product_id')->on('b.organization_id', '=', 'op.organization_id')
                ->where('b.stock_state', 'available')->when($warehouses !== null, fn ($j) => $j->whereIn('b.warehouse_id', $warehouses)))
            ->leftJoin('batches as bt', 'bt.id', '=', 'b.batch_id')
            ->where('op.organization_id', $org)->where('op.status', 'active')
            ->groupBy('op.product_id', 'op.sku', 'p.display_name', 'op.reorder_level', 'op.reorder_quantity', 'op.preferred_supplier_id')
            ->selectRaw("op.product_id, op.sku, p.display_name as product_name, op.reorder_level, op.reorder_quantity, op.preferred_supplier_id,
                COALESCE(SUM(CASE WHEN bt.quality_status = 'released' AND bt.recall_status = 'none' AND (bt.expiry_date IS NULL OR bt.expiry_date >= ?) THEN b.quantity - b.reserved_quantity END), 0) as sellable", [$today])
            ->get();
        $average = $this->demand->averageDaily($org, $warehouses, config('analytics.demand_window_days'));

        return $stock->map(function ($r) use ($average) {
            $daily = $average[$r->product_id] ?? '0';
            $sellable = Figures::dec($r->sellable);

            return ['product_id' => $r->product_id, 'sku' => $r->sku, 'product_name' => $r->product_name, 'sellable' => $sellable,
                'reorder_level' => $r->reorder_level === null ? null : Figures::dec($r->reorder_level),
                'reorder_quantity' => $r->reorder_quantity === null ? null : Figures::dec($r->reorder_quantity),
                'preferred_supplier_id' => $r->preferred_supplier_id, 'average_daily_demand' => $daily,
                'days_of_cover' => bccomp($daily, '0', 4) > 0 ? round((float) bcdiv($sellable, $daily, 4), 1) : null];
        });
    }
}

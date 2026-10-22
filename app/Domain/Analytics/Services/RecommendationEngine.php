<?php

namespace App\Domain\Analytics\Services;

use App\Domain\Analytics\Models\Recommendation;
use App\Domain\Analytics\Support\AnalyticsDb;
use App\Domain\Traceability\Services\TraceChain;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Generates recommendations, predictions and anomalies for one organization (spec §19.2):
 * reorder, overstock, expiry risk, integration anomalies and traceability anomalies.
 *
 * Read-only towards the business: it reads analytics and writes only its own
 * recommendation rows. It never creates requisitions or orders, moves stock, posts
 * journals or changes regulatory records; a person decides (RecommendationService).
 *
 * One open recommendation per subject: a rerun refreshes it, and one whose condition has
 * cleared is marked superseded.
 */
class RecommendationEngine
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly InventoryAnalytics $inventory,
        private readonly ForecastService $forecasts,
        private readonly TraceChain $chain,
    ) {}

    /** @return array{open: int, created: int, refreshed: int, superseded: int} */
    public function generate(): array
    {
        $org = $this->tenant->id();
        $found = array_merge($this->reorderAndOverstock($org), $this->expiryRisk($org), $this->integrationAnomalies($org), $this->traceabilityAnomalies($org));

        return DB::transaction(function () use ($found) {
            $result = ['open' => 0, 'created' => 0, 'refreshed' => 0, 'superseded' => 0];
            $keys = [];
            foreach ($found as $rec) {
                $keys[] = $rec['subject_key'];
                $existing = Recommendation::query()->where('subject_key', $rec['subject_key'])->where('status', 'open')->lockForUpdate()->first();
                if ($existing) {
                    $existing->fill($rec + ['generated_at' => now()])->save();
                    $result['refreshed']++;
                } else {
                    Recommendation::query()->create($rec + ['generated_at' => now(), 'generator' => $rec['generator'] ?? 'rules-v1']);
                    $result['created']++;
                }
            }
            $result['superseded'] = Recommendation::query()->where('status', 'open')->whereNotIn('subject_key', $keys ?: [''])
                ->update(['status' => 'superseded', 'updated_at' => now()]);
            $result['open'] = count($keys);

            return $result;
        });
    }

    private function reorderAndOverstock(string $org): array
    {
        $config = config('analytics.recommendations');
        $onOrder = $this->onOrder($org);
        $out = [];

        foreach ($this->inventory->cover($org, null) as $p) {
            $sellable = (float) $p['sellable'];
            $daily = (float) $p['average_daily_demand'];
            $incoming = (float) ($onOrder[$p['product_id']] ?? 0);
            $lead = $this->leadTimeDays($org, $p['preferred_supplier_id']);
            $atReorderLevel = $p['reorder_level'] !== null && $sellable + $incoming <= (float) $p['reorder_level'];

            if ($daily > 0 || $atReorderLevel) {
                $duringLead = $this->forecasts->expectedDemand($org, $p['product_id'], null, $lead['days']);
                $duringCycle = $this->forecasts->expectedDemand($org, $p['product_id'], null, $lead['days'] + $config['target_cover_days']);
                $basis = $duringLead ? 'forecast' : 'average';
                $needLead = $duringLead['quantity'] ?? $daily * $lead['days'];
                $needCycle = $duringCycle['quantity'] ?? $daily * ($lead['days'] + $config['target_cover_days']);
                $runsOut = $sellable + $incoming < $needLead;

                if ($runsOut || $atReorderLevel) {
                    $suggested = (int) ceil(max($needCycle - $sellable - $incoming, (float) ($p['reorder_quantity'] ?? 0)));
                    if ($suggested > 0) {
                        $warehouse = $this->receivingWarehouse($org, $p['product_id']);
                        $out[] = [
                            'type' => 'reorder', 'kind' => 'recommendation', 'severity' => $runsOut ? 'critical' : 'warning',
                            'subject_key' => "reorder:{$p['product_id']}", 'product_id' => $p['product_id'], 'warehouse_id' => $warehouse,
                            'title' => "Reorder {$p['product_name']}: about {$suggested} units",
                            'rationale' => trim(($runsOut
                                ? sprintf('Sellable stock (%s) plus stock on order (%s) is below the %s units expected to sell during the %d-day lead time, so stock is likely to run out before a new delivery arrives.',
                                    $this->n($sellable), $this->n($incoming), $this->n($needLead), $lead['days'])
                                : sprintf('Sellable stock (%s) plus stock on order (%s) is at or below the reorder level (%s).', $this->n($sellable), $this->n($incoming), $p['reorder_level']))
                                .sprintf(' The suggestion covers the lead time plus %d days of demand (%s units%s), less stock on hand and on order%s.',
                                    $config['target_cover_days'], $this->n($needCycle), $basis === 'forecast' ? ', forecast' : ', at the recent average',
                                    $p['reorder_quantity'] !== null ? ", and at least the reorder quantity ({$p['reorder_quantity']})" : '')),
                            'figures' => ['sellable' => $p['sellable'], 'on_order' => $this->n($incoming), 'reorder_level' => $p['reorder_level'], 'reorder_quantity' => $p['reorder_quantity'],
                                'average_daily_demand' => $p['average_daily_demand'], 'lead_time_days' => $lead['days'], 'lead_time_basis' => $lead['basis'],
                                'expected_demand_lead_time' => $this->n($needLead), 'expected_demand_cycle' => $this->n($needCycle), 'demand_basis' => $basis,
                                'forecast_method' => $duringCycle['method'] ?? null, 'forecast_accuracy' => $duringCycle['accuracy'] ?? null,
                                'suggested_quantity' => (string) $suggested, 'unit' => 'base units', 'suggested_supplier_id' => $p['preferred_supplier_id']],
                            'assumptions' => array_values(array_filter([
                                $lead['basis'] === 'default' ? "Lead time is the default of {$lead['days']} days; no receipt history from the preferred supplier." : "Lead time is the preferred supplier's average of {$lead['days']} days.",
                                'Stock on order includes approved, sent and part-received purchase orders and open requisitions.',
                                $basis === 'forecast' ? 'Expected demand comes from the weekly demand forecast.' : 'Too little history for a forecast; the recent daily average is used.',
                                'Suggested quantity is in base units; choose the pack size when you review the requisition.',
                            ])),
                            'generator' => $duringCycle ? $duringCycle['provider'].'/'.$duringCycle['method'] : 'average-v1',
                        ];
                    }
                }
            }

            $overstocked = $p['days_of_cover'] !== null && $p['days_of_cover'] > $config['overstock_cover_days'];
            if ($overstocked) {
                $out[] = [
                    'type' => 'overstock', 'kind' => 'prediction', 'severity' => 'info', 'subject_key' => "overstock:{$p['product_id']}",
                    'product_id' => $p['product_id'], 'warehouse_id' => null,
                    'title' => "{$p['product_name']}: about ".round($p['days_of_cover']).' days of stock',
                    'rationale' => sprintf('At the recent average of %s units a day, sellable stock of %s lasts about %d days, above the %d-day threshold. Consider pausing orders or moving stock to where it sells.',
                        $p['average_daily_demand'], $p['sellable'], round($p['days_of_cover']), $config['overstock_cover_days']),
                    'figures' => ['sellable' => $p['sellable'], 'on_order' => $this->n($incoming), 'average_daily_demand' => $p['average_daily_demand'], 'days_of_cover' => $p['days_of_cover']],
                    'assumptions' => ['Average net daily demand over the last '.config('analytics.demand_window_days').' days continues.'],
                ];
            }
        }

        return $out;
    }

    private function expiryRisk(string $org): array
    {
        $window = config('analytics.recommendations.expiry_window_days');

        return collect($this->inventory->expiryAtRisk($org, null, false))->filter(fn ($l) => $l['days_to_expiry'] <= $window)
            ->map(fn ($l) => [
                'type' => 'expiry_risk', 'kind' => 'prediction', 'severity' => $l['days_to_expiry'] <= 30 ? 'critical' : 'warning',
                'subject_key' => "expiry:{$l['batch_id']}", 'product_id' => $l['product_id'], 'warehouse_id' => null,
                'subject_type' => 'batch', 'subject_id' => $l['batch_id'],
                'title' => "{$l['product_name']} lot {$l['batch_number']}: about ".round((float) $l['estimated_unsold']).' units may expire unsold',
                'rationale' => sprintf('Lot %s expires on %s (%d days). At the recent demand of %s units a day, used first-expiry-first-out, about %s of the %s available units will not sell in time. Options: move it to a location that sells it, agree a return with the supplier, or prioritise it in sales.',
                    $l['batch_number'], $l['expiry_date'], $l['days_to_expiry'], $l['average_daily_demand'], $this->n((float) $l['estimated_unsold']), $l['available']),
                'figures' => array_intersect_key($l, array_flip(['batch_number', 'expiry_date', 'days_to_expiry', 'available', 'average_daily_demand', 'estimated_unsold'])),
                'assumptions' => ['Average net daily demand over the last '.config('analytics.demand_window_days').' days continues.', 'Earlier-expiring lots of the same product sell first.'],
            ])->values()->all();
    }

    /** A connector whose problem rate jumped, or that went quiet after receiving steadily. */
    private function integrationAnomalies(string $org): array
    {
        $since = now()->subDay();
        $baselineFrom = now()->subDays(15);
        $out = [];
        foreach (AnalyticsDb::table('integration_connectors')->where('organization_id', $org)->where('status', 'active')->get(['id', 'code', 'name']) as $c) {
            $stats = fn ($from, $to) => AnalyticsDb::table('integration_inbox')->where('organization_id', $org)->where('connector_id', $c->id)->whereBetween('received_at', [$from, $to])
                ->selectRaw("COUNT(*) as received, SUM(CASE WHEN status IN ('quarantined', 'failed') THEN 1 ELSE 0 END) as problems")->first();
            $recent = $stats($since, now());
            $base = $stats($baselineFrom, $since);
            $recentRate = $recent->received > 0 ? $recent->problems / $recent->received : 0.0;
            $baseRate = $base->received > 0 ? $base->problems / $base->received : 0.0;

            if ((int) $recent->problems >= 5 && $recentRate >= 0.2 && $recentRate >= 3 * max($baseRate, 0.01)) {
                $out[] = ['type' => 'integration_anomaly', 'kind' => 'anomaly', 'severity' => $recentRate >= 0.5 ? 'critical' : 'warning',
                    'subject_key' => "integration_errors:{$c->id}", 'subject_type' => 'connector', 'subject_id' => $c->id,
                    'title' => "{$c->name}: ".round($recentRate * 100).'% of messages failed or were quarantined in the last 24 hours',
                    'rationale' => sprintf('%d of %d messages in the last 24 hours were quarantined or failed, against %s%% over the previous 14 days. Check the quarantine for a mapping or master-data change on the %s side.',
                        $recent->problems, $recent->received, round($baseRate * 100, 1), $c->code),
                    'figures' => ['received_24h' => (int) $recent->received, 'problems_24h' => (int) $recent->problems, 'problem_percent_24h' => round($recentRate * 100, 1),
                        'baseline_received' => (int) $base->received, 'baseline_problem_percent' => round($baseRate * 100, 1)],
                    'assumptions' => ['Baseline is the 14 days before the last 24 hours.']];
            }

            $activeDays = AnalyticsDb::table('integration_inbox')->where('organization_id', $org)->where('connector_id', $c->id)
                ->whereBetween('received_at', [now()->subDays(8), $since])->distinct()->count(DB::raw('DATE(received_at)'));
            if ((int) $recent->received === 0 && $activeDays >= 5) {
                $out[] = ['type' => 'integration_anomaly', 'kind' => 'anomaly', 'severity' => 'warning',
                    'subject_key' => "integration_silent:{$c->id}", 'subject_type' => 'connector', 'subject_id' => $c->id,
                    'title' => "{$c->name}: no messages in the last 24 hours",
                    'rationale' => "The connector received messages on {$activeDays} of the previous 7 days and none in the last 24 hours. The sending system or its job may have stopped.",
                    'figures' => ['received_24h' => 0, 'active_days_previous_week' => $activeDays], 'assumptions' => ['A quiet day can be normal on public holidays.']];
            }
        }

        return $out;
    }

    /** A broken hash chain, or the organization's serials scanned as already dispensed or withdrawn. */
    private function traceabilityAnomalies(string $org): array
    {
        $out = [];
        $integrity = $this->chain->verify($org);
        if (! $integrity['intact']) {
            $out[] = ['type' => 'traceability_anomaly', 'kind' => 'anomaly', 'severity' => 'critical', 'subject_key' => 'trace_chain',
                'title' => 'Traceability records fail the integrity check',
                'rationale' => "The hash chain breaks at sequence {$integrity['first_broken_sequence']}: a recorded event was changed or removed outside the application. Investigate before relying on these records.",
                'figures' => $integrity, 'assumptions' => []];
        }

        $suspicious = AnalyticsDb::table('trace_scans as s')->join('trace_identifiers as i', 'i.id', '=', 's.identifier_id')
            ->where('i.organization_id', $org)->whereIn('s.result', ['dispensed', 'revoked'])->where('s.scanned_at', '>=', now()->subDays(7))
            ->groupBy('i.id', 'i.kind', 'i.batch_number', 'i.serial', 's.result')
            ->selectRaw('i.id, i.kind, i.batch_number, i.serial, s.result, COUNT(*) as scans, COUNT(DISTINCT s.client_hash) as scanners, MAX(s.scanned_at) as last_scan')->get();
        foreach ($suspicious as $s) {
            $out[] = ['type' => 'traceability_anomaly', 'kind' => 'anomaly', 'severity' => $s->scanners >= 3 ? 'critical' : 'warning',
                'subject_key' => "trace_scan:{$s->id}", 'subject_type' => 'identifier', 'subject_id' => $s->id,
                'title' => ($s->result === 'dispensed' ? 'Dispensed' : 'Withdrawn')." code scanned again ({$s->batch_number}".($s->serial ? " / {$s->serial}" : '').')',
                'rationale' => sprintf('In the last 7 days this %s code was scanned %d times by %d different scanners after it was %s. A pack carrying it may be a copy; consider a regulatory incident if it is confirmed.',
                    $s->kind, $s->scans, $s->scanners, $s->result === 'dispensed' ? 'dispensed' : 'withdrawn'),
                'figures' => ['result' => $s->result, 'scans' => (int) $s->scans, 'distinct_scanners' => (int) $s->scanners, 'last_scan' => $s->last_scan],
                'assumptions' => ['Scanners are distinguished by an anonymous hash of the client, not by identity.']];
        }

        return $out;
    }

    /** @return array<string, float> product_id => base units on order or requested */
    private function onOrder(string $org): array
    {
        $po = AnalyticsDb::table('purchase_order_lines as l')->join('purchase_orders as o', 'o.id', '=', 'l.purchase_order_id')
            ->where('o.organization_id', $org)->whereIn('o.status', ['approved', 'sent', 'partially_received'])
            ->groupBy('l.product_id')->selectRaw('l.product_id, SUM(GREATEST(l.base_quantity - l.received_base_quantity, 0)) as q')->pluck('q', 'product_id');
        $requested = AnalyticsDb::table('purchase_requisition_lines as l')->join('purchase_requisitions as r', 'r.id', '=', 'l.purchase_requisition_id')
            ->where('r.organization_id', $org)->whereIn('r.status', ['draft', 'submitted', 'approved'])
            ->groupBy('l.product_id')->selectRaw('l.product_id, SUM(l.base_quantity) as q')->pluck('q', 'product_id');

        return $po->keys()->merge($requested->keys())->unique()->mapWithKeys(fn ($id) => [$id => (float) ($po[$id] ?? 0) + (float) ($requested[$id] ?? 0)])->all();
    }

    /** @return array{days: int, basis: string} */
    private function leadTimeDays(string $org, ?string $supplierId): array
    {
        if ($supplierId !== null) {
            $lead = AnalyticsDb::table('purchase_orders as o')->joinSub(AnalyticsDb::table('goods_receipts')->where('organization_id', $org)->whereNotNull('purchase_order_id')
                ->groupBy('purchase_order_id')->selectRaw('purchase_order_id, MIN(received_at) as first_received'), 'r', 'r.purchase_order_id', '=', 'o.id')
                ->where('o.organization_id', $org)->where('o.supplier_id', $supplierId)
                ->selectRaw('AVG(DATEDIFF(r.first_received, COALESCE(o.sent_at, o.order_date))) as days, COUNT(*) as n')->first();
            if ($lead && (int) $lead->n >= 2) {
                return ['days' => max(1, (int) round($lead->days)), 'basis' => 'supplier_history'];
            }
        }

        return ['days' => config('analytics.recommendations.default_lead_time_days'), 'basis' => 'default'];
    }

    /** Where a reorder would be received: the warehouse that sells the most of it, else the one holding most, else any. */
    private function receivingWarehouse(string $org, string $productId): ?string
    {
        return AnalyticsDb::table('analytics_daily_demand')->where('organization_id', $org)->where('product_id', $productId)
            ->where('day', '>=', today()->subDays(config('analytics.demand_window_days'))->toDateString())
            ->groupBy('warehouse_id')->orderByRaw('SUM(quantity_out) DESC')->value('warehouse_id')
            ?? AnalyticsDb::table('inventory_balances')->where('organization_id', $org)->where('product_id', $productId)
                ->groupBy('warehouse_id')->orderByRaw('SUM(quantity) DESC')->value('warehouse_id')
            ?? AnalyticsDb::table('warehouses')->where('organization_id', $org)->where('status', 'active')->orderBy('code')->value('id');
    }

    private function n(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}

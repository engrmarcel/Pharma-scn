<?php

namespace App\Domain\Analytics\Services;

use App\Domain\Crm\Services\CrmAnalytics;
use App\Support\Exceptions\BusinessRuleViolation;
use Illuminate\Support\Facades\Gate;

/**
 * Executive dashboard (spec §31 Phase 8): headline figures from each area, built
 * from the same services as the area dashboards and under the same rules. A
 * section the viewer may not see is listed as withheld with the reason; it is
 * never shown as zero.
 */
class ExecutiveDashboard
{
    public function __construct(
        private readonly AnalyticsScope $scope,
        private readonly CommercialAnalytics $commercial,
        private readonly InventoryAnalytics $inventory,
        private readonly OperationsAnalytics $operations,
    ) {}

    public function build(string $from, string $to): array
    {
        $this->scope->authorize('analytics.view');
        $sections = [
            'sales' => fn () => $this->salesHeadline($this->commercial->sales($from, $to)),
            'purchases' => fn () => array_intersect_key($this->commercial->purchases($from, $to), array_flip(['committed_spend', 'orders_by_status'])),
            'inventory' => fn () => $this->inventoryHeadline($this->inventory->inventory($from, $to)),
            'expiry_risk' => fn () => $this->expiryHeadline($this->inventory->expiryRisk()),
            'pos' => fn () => array_intersect_key($this->commercial->pos($from, $to), array_flip(['sales', 'gross', 'average_basket', 'refunds', 'offline_percent'])),
            'field_force' => fn () => $this->fieldForce($from, $to),
            'marketplace' => fn () => array_map(fn ($side) => array_intersect_key($side, array_flip(['orders_by_status', 'value'])),
                array_intersect_key($this->commercial->marketplace($from, $to), array_flip(['as_buyer', 'as_supplier']))),
            'integrations' => fn () => $this->integrationHeadline($this->operations->integrations($from, $to)),
            'sync' => fn () => array_intersect_key($this->operations->sync($from, $to), array_flip(['open_issues', 'devices'])),
            'recalls' => fn () => array_intersect_key($this->operations->recalls(), array_flip(['notices_by_status', 'incidents'])),
        ];

        $out = ['from' => $from, 'to' => $to, 'locations' => $this->scope->allLocations() ? 'all' : count($this->scope->warehouseIds()), 'sections' => [], 'withheld' => []];
        foreach ($sections as $name => $build) {
            try {
                $out['sections'][$name] = $build();
            } catch (BusinessRuleViolation $e) {
                if (! in_array($e->errorCode, ['forbidden', 'analytics_requires_all_locations'], true)) {
                    throw $e;
                }
                $out['withheld'][] = ['section' => $name, 'reason' => $e->errorCode === 'forbidden' ? 'permission' : 'location_scope'];
            }
        }

        return $out;
    }

    private function salesHeadline(array $s): array
    {
        return ['invoices' => $s['invoices'], 'revenue' => $s['revenue'], 'credited' => $s['credited'], 'receivables' => $s['customers']['receivables'],
            'overdue' => $s['customers']['overdue'], 'active_customers' => $s['customers']['active_customers'], 'trend' => $s['trend']];
    }

    private function inventoryHeadline(array $i): array
    {
        return ['products_in_stock' => $i['products_in_stock'], 'stock_value' => $i['stock_value'], 'below_reorder_level' => $i['below_reorder_level'],
            'stockout_risk' => count($i['stockout_risk']), 'slow_moving' => count($i['slow_moving'])];
    }

    private function expiryHeadline(array $e): array
    {
        return ['buckets' => $e['buckets'], 'at_risk_batches' => $e['at_risk_batches'], 'kind' => $e['estimates']['kind']];
    }

    private function integrationHeadline(array $i): array
    {
        return ['connectors_by_health' => collect($i['connectors'])->countBy('health'), 'messages_by_status' => $i['messages_by_status'], 'webhook_success_percent' => $i['webhooks']['success_percent']];
    }

    private function fieldForce(string $from, string $to): array
    {
        if (! Gate::allows('field_force.reports.view')) {
            throw new BusinessRuleViolation('forbidden', 'Needs field_force.reports.view.', 403);
        }
        $a = app(CrmAnalytics::class)->build($from, $to);

        return ['coverage' => $a['coverage'], 'visits' => array_intersect_key($a['visits'], array_flip(['total', 'completed', 'verified_percent'])), 'conversion' => $a['conversion']];
    }
}

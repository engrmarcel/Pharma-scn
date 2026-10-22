<?php

namespace App\Domain\Analytics\Assistant;

use App\Domain\Analytics\Services\CommercialAnalytics;
use App\Domain\Analytics\Services\ForecastService;
use App\Domain\Analytics\Services\InventoryAnalytics;
use App\Domain\Analytics\Services\OperationsAnalytics;
use App\Domain\Analytics\Services\RecommendationService;
use App\Domain\Crm\Services\CrmAnalytics;
use App\Support\Exceptions\BusinessRuleViolation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The only things the reporting assistant can do: run read-only reports, each through
 * the same service (and so the same permission and location checks) as its dashboard.
 * There is no write tool. A plan naming anything else is rejected before it runs.
 */
class ReportCatalog
{
    public const TOOLS = [
        'sales_summary' => 'Sales revenue, invoices, top products and customers, receivables and overdue amounts for a period.',
        'purchases_summary' => 'Purchase orders, committed spend and supplier performance (fill rate, on-time, lead time) for a period.',
        'inventory_summary' => 'Stock on hand, stock value, products below reorder level, stockout risk and slow movers.',
        'expiry_risk' => 'Stock close to expiry and batches predicted to expire unsold.',
        'pos_summary' => 'Point-of-sale sales, basket size, refunds, offline share, terminals and cashiers for a period.',
        'marketplace_summary' => 'Marketplace orders as buyer and as supplier, values and fees for a period.',
        'integration_health' => 'Integration connectors, message errors and webhook delivery for a period.',
        'sync_status' => 'Offline device synchronization: transactions, delays, open issues, devices.',
        'recall_status' => 'Recall notices to act on and regulatory incidents.',
        'field_force_summary' => 'Field visits, coverage and orders captured by representatives for a period.',
        'demand_forecast' => 'Weekly demand forecast for one product (needs product_query).',
        'open_recommendations' => 'Open machine-generated recommendations (reorder, overstock, expiry risk, anomalies).',
    ];

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly CommercialAnalytics $commercial,
        private readonly InventoryAnalytics $inventory,
        private readonly OperationsAnalytics $operations,
        private readonly ForecastService $forecasts,
        private readonly RecommendationService $recommendations,
    ) {}

    /**
     * @param  array{tool: string, from: string, to: string, product_query?: ?string}  $step
     * @return array{status: string, data?: array, message?: string}
     */
    public function run(array $step): array
    {
        if (! array_key_exists($step['tool'], self::TOOLS)) {
            return ['status' => 'rejected', 'message' => 'Not an available report.'];
        }
        [$from, $to] = [$step['from'], $step['to']];

        try {
            $data = match ($step['tool']) {
                'sales_summary' => $this->commercial->sales($from, $to),
                'purchases_summary' => $this->commercial->purchases($from, $to),
                'inventory_summary' => $this->inventory->inventory($from, $to),
                'expiry_risk' => $this->inventory->expiryRisk(),
                'pos_summary' => $this->commercial->pos($from, $to),
                'marketplace_summary' => $this->commercial->marketplace($from, $to),
                'integration_health' => $this->operations->integrations($from, $to),
                'sync_status' => $this->operations->sync($from, $to),
                'recall_status' => $this->operations->recalls(),
                'field_force_summary' => $this->fieldForce($from, $to),
                'demand_forecast' => $this->forecast($step['product_query'] ?? null),
                'open_recommendations' => ['items' => $this->recommendations->visible()->where('status', 'open')
                    ->orderByRaw("FIELD(severity, 'critical', 'warning', 'info')")->orderByDesc('generated_at')->limit(20)->get()
                    ->map(fn ($r) => $r->only('id', 'type', 'kind', 'severity', 'title'))->all()],
            };

            return ['status' => 'ok', 'data' => $data];
        } catch (BusinessRuleViolation $e) {
            $withheld = in_array($e->errorCode, ['forbidden', 'analytics_requires_all_locations', 'location_access_denied'], true);

            return ['status' => $withheld ? 'withheld' : 'unavailable', 'message' => $e->getMessage()];
        } catch (AuthorizationException) {
            return ['status' => 'withheld', 'message' => 'You do not have permission to view these figures.'];
        }
    }

    /** Products of the organization whose name or SKU matches the text (for forecasts). */
    public function findProduct(?string $text): ?object
    {
        $text = trim((string) $text);
        if ($text === '') {
            return null;
        }
        $products = DB::table('organization_products as op')->join('products as p', 'p.id', '=', 'op.product_id')
            ->leftJoin('brands as b', 'b.id', '=', 'p.brand_id')
            ->where('op.organization_id', $this->tenant->id())->where('op.status', 'active')
            ->get(['op.product_id', 'op.sku', 'p.display_name', 'b.name as brand_name', 'p.generic_name', 'op.local_name']);
        $needle = mb_strtolower($text);
        $best = null;
        foreach ($products as $p) {
            foreach ([$p->sku, $p->brand_name, $p->local_name, $p->generic_name, $p->display_name] as $candidate) {
                $c = mb_strtolower((string) $candidate);
                if ($c !== '' && mb_strlen($c) >= 3 && (str_contains($needle, $c) || str_contains($c, $needle))) {
                    $score = mb_strlen($c);
                    if ($best === null || $score > $best[0]) {
                        $best = [$score, $p];
                    }
                }
            }
        }

        return $best[1] ?? null;
    }

    private function forecast(?string $productQuery): array
    {
        $product = $this->findProduct($productQuery)
            ?? throw new BusinessRuleViolation('product_not_identified', 'Name the product (brand, generic name or SKU) to forecast.', 422);

        return $this->forecasts->forecast($product->product_id, null, 8) + ['product_name' => $product->display_name, 'sku' => $product->sku];
    }

    private function fieldForce(string $from, string $to): array
    {
        if (! Gate::allows('field_force.reports.view')) {
            throw new BusinessRuleViolation('forbidden', 'You do not have permission to view field-force figures.', 403);
        }

        return app(CrmAnalytics::class)->build($from, $to);
    }
}

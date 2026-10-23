<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Access\Services\AccessControl;
use App\Domain\Analytics\Services\AnalyticsScope;
use App\Domain\Analytics\Services\CommercialAnalytics;
use App\Domain\Analytics\Services\ExecutiveDashboard;
use App\Domain\Analytics\Services\InventoryAnalytics;
use App\Domain\Analytics\Services\OperationsAnalytics;
use App\Domain\Analytics\Services\PlatformAnalytics;
use App\Domain\Crm\Services\CrmAnalytics;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Dashboards (spec §19.1). Every figure is computed for the current organization and,
 * for location-restricted members, their warehouses only; each dashboard checks its
 * area's view permission. See AnalyticsScope.
 */
class AnalyticsController extends Controller
{
    public const DASHBOARDS = ['sales', 'purchases', 'inventory', 'expiry-risk', 'pos', 'field-force', 'marketplace', 'integrations', 'sync', 'recalls'];

    public function executive(Request $request, ExecutiveDashboard $dashboard): JsonResponse
    {
        [$from, $to] = $this->period($request);

        return $this->cached($request, 'executive', compact('from', 'to'), fn () => $dashboard->build($from, $to));
    }

    public function dashboard(Request $request, string $dashboard, CommercialAnalytics $commercial, InventoryAnalytics $inventory, OperationsAnalytics $operations): JsonResponse
    {
        [$from, $to] = $this->period($request);
        $warehouse = $request->validate(['warehouse_id' => ['nullable', 'ulid']])['warehouse_id'] ?? null;

        return $this->cached($request, $dashboard, compact('from', 'to', 'warehouse') + ['territory_id' => $request->query('territory_id')], fn () => match ($dashboard) {
            'sales' => $commercial->sales($from, $to, $warehouse),
            'purchases' => $commercial->purchases($from, $to, $warehouse),
            'pos' => $commercial->pos($from, $to, $warehouse),
            'marketplace' => $commercial->marketplace($from, $to),
            'inventory' => $inventory->inventory($from, $to, $warehouse),
            'expiry-risk' => $inventory->expiryRisk($warehouse),
            'field-force' => $this->fieldForce($request, $from, $to),
            'integrations' => $operations->integrations($from, $to),
            'sync' => $operations->sync($from, $to),
            'recalls' => $operations->recalls(),
        });
    }

    /**
     * Dashboards are recomputed live and are the costliest reads (Phase 10 load test), so a result is
     * kept briefly per viewer. The key holds the organization, the viewer, their current permissions
     * and warehouse restrictions, so a change in access takes effect at once; only the figures can be
     * up to `analytics.dashboard_cache_seconds` old, which `generated_at` states.
     */
    private function cached(Request $request, string $dashboard, array $params, \Closure $build): JsonResponse
    {
        $ttl = (int) config('analytics.dashboard_cache_seconds');
        if ($ttl <= 0) {
            return response()->json(['data' => $build(), 'meta' => ['generated_at' => now()->toIso8601String()]]);
        }
        $scope = app(AnalyticsScope::class);
        $permissions = app(AccessControl::class)->organizationPermissions($request->user());
        ksort($permissions);
        $key = 'analytics:'.$scope->organizationId().':'.$request->user()->id.':'.$dashboard.':'.sha1(json_encode([
            $params, array_keys($permissions), $scope->warehouseIds(),
        ]));

        // Authorization runs inside the builders, so a cached result is only ever served to the same
        // viewer with the same permissions that already passed it.
        $result = Cache::remember($key, $ttl, fn () => ['data' => $build(), 'generated_at' => now()->toIso8601String()]);

        return response()->json(['data' => $result['data'], 'meta' => ['generated_at' => $result['generated_at']]]);
    }

    public function platform(Request $request, PlatformAnalytics $platform): JsonResponse
    {
        [$from, $to] = $this->period($request);
        $currency = $request->validate(['reporting_currency' => ['nullable', Rule::in(array_keys(config('currencies')))]])['reporting_currency'] ?? null;

        return response()->json(['data' => $platform->build($from, $to, $currency)]);
    }

    private function fieldForce(Request $request, string $from, string $to): array
    {
        Gate::authorize('field_force.reports.view');

        return app(CrmAnalytics::class)->build($from, $to, $request->validate(['territory_id' => ['nullable', 'ulid']])['territory_id'] ?? null);
    }

    /** @return array{0: string, 1: string} defaults to the month to date */
    private function period(Request $request): array
    {
        $data = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from']]);

        return [$data['from'] ?? business_today()->startOfMonth()->toDateString(), $data['to'] ?? business_today()->toDateString()];
    }
}

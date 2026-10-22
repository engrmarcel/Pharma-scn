<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Analytics\Services\CommercialAnalytics;
use App\Domain\Analytics\Services\ExecutiveDashboard;
use App\Domain\Analytics\Services\InventoryAnalytics;
use App\Domain\Analytics\Services\OperationsAnalytics;
use App\Domain\Analytics\Services\PlatformAnalytics;
use App\Domain\Crm\Services\CrmAnalytics;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

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

        return response()->json(['data' => $dashboard->build($from, $to)]);
    }

    public function dashboard(Request $request, string $dashboard, CommercialAnalytics $commercial, InventoryAnalytics $inventory, OperationsAnalytics $operations): JsonResponse
    {
        [$from, $to] = $this->period($request);
        $warehouse = $request->validate(['warehouse_id' => ['nullable', 'ulid']])['warehouse_id'] ?? null;

        $data = match ($dashboard) {
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
        };

        return response()->json(['data' => $data]);
    }

    public function platform(Request $request, PlatformAnalytics $platform): JsonResponse
    {
        [$from, $to] = $this->period($request);

        return response()->json(['data' => $platform->build($from, $to)]);
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

        return [$data['from'] ?? today()->startOfMonth()->toDateString(), $data['to'] ?? today()->toDateString()];
    }
}

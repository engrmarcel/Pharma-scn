<?php

namespace App\Domain\Analytics\Services;

use App\Domain\Analytics\Support\AnalyticsDb;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Daily demand facts: base units issued to customers and patients (inventory
 * movements of type sale, net of sale_return) per organization, warehouse,
 * product and day. Rebuilt from the ledger, which stays the source of truth;
 * a refresh replaces the whole day range, so it can be rerun safely.
 */
class DemandFacts
{
    /** @return array{rows: int, from: string, to: string} */
    public function refresh(string $organizationId, Carbon $from, Carbon $to): array
    {
        $started = hrtime(true);
        [$fromDay, $toDay] = [$from->toDateString(), $to->toDateString()];

        $rows = DB::transaction(function () use ($organizationId, $fromDay, $toDay) {
            DB::table('analytics_daily_demand')->where('organization_id', $organizationId)->whereBetween('day', [$fromDay, $toDay])->delete();

            return DB::affectingStatement(<<<'SQL'
                INSERT INTO analytics_daily_demand (organization_id, warehouse_id, product_id, day, quantity_out, quantity_returned, movements)
                SELECT organization_id, warehouse_id, product_id, DATE(posted_at),
                       SUM(CASE WHEN movement_type = 'sale' THEN -quantity ELSE 0 END),
                       SUM(CASE WHEN movement_type = 'sale_return' THEN quantity ELSE 0 END),
                       COUNT(*)
                FROM inventory_movements
                WHERE organization_id = ? AND movement_type IN ('sale', 'sale_return') AND posted_at >= ? AND posted_at < ?
                GROUP BY organization_id, warehouse_id, product_id, DATE(posted_at)
                SQL, [$organizationId, $fromDay.' 00:00:00', Carbon::parse($toDay)->addDay()->toDateString().' 00:00:00']);
        });

        DB::table('analytics_refreshes')->insert(['organization_id' => $organizationId, 'dataset' => 'daily_demand', 'from_day' => $fromDay, 'to_day' => $toDay,
            'rows' => $rows, 'duration_ms' => (int) ((hrtime(true) - $started) / 1e6), 'refreshed_at' => now()]);

        return ['rows' => $rows, 'from' => $fromDay, 'to' => $toDay];
    }

    /** When the facts were last rebuilt for the organization (shown on every dashboard that uses them). */
    public function lastRefresh(string $organizationId): ?array
    {
        $r = AnalyticsDb::table('analytics_refreshes')->where('organization_id', $organizationId)->where('dataset', 'daily_demand')
            ->orderByDesc('refreshed_at')->orderByDesc('id')->first(['to_day', 'refreshed_at']);

        return $r ? ['through' => $r->to_day, 'refreshed_at' => Carbon::parse($r->refreshed_at)->toIso8601String()] : null;
    }

    /**
     * Average net daily demand per product (and warehouse, when given) over the trailing window.
     *
     * @param  list<string>|null  $warehouses
     * @return Collection<string, string> product_id => average base units per day (4 dp)
     */
    public function averageDaily(string $organizationId, ?array $warehouses, int $days, ?Carbon $until = null): Collection
    {
        $until ??= today();
        $from = $until->copy()->subDays($days - 1)->toDateString();

        return AnalyticsDb::table('analytics_daily_demand')->where('organization_id', $organizationId)
            ->whereBetween('day', [$from, $until->toDateString()])
            ->when($warehouses !== null, fn ($q) => $q->whereIn('warehouse_id', $warehouses))
            ->groupBy('product_id')->selectRaw('product_id, SUM(quantity_out - quantity_returned) as net')
            ->pluck('net', 'product_id')
            ->map(fn ($net) => bcdiv(bccomp((string) $net, '0', 4) > 0 ? (string) $net : '0', (string) $days, 4));
    }

    /**
     * Net demand per bucket (day, week or month) for one product, for trends and forecasting.
     *
     * @param  list<string>|null  $warehouses
     * @return list<array{period: string, quantity: string}>
     */
    public function series(string $organizationId, ?string $productId, ?array $warehouses, Carbon $from, Carbon $to, string $bucket = 'week'): array
    {
        $rows = AnalyticsDb::table('analytics_daily_demand')->where('organization_id', $organizationId)
            ->when($productId, fn ($q, $v) => $q->where('product_id', $v))
            ->when($warehouses !== null, fn ($q) => $q->whereIn('warehouse_id', $warehouses))
            ->whereBetween('day', [$from->toDateString(), $to->toDateString()])
            ->groupBy('day')->selectRaw('day, SUM(quantity_out - quantity_returned) as net')->pluck('net', 'day');

        $series = [];
        for ($d = $from->copy()->startOfDay(); $d->lte($to); $d->addDay()) {
            $key = match ($bucket) {
                'day' => $d->toDateString(),
                'week' => $d->copy()->startOfWeek()->toDateString(),
                'month' => $d->format('Y-m'),
            };
            $series[$key] = bcadd($series[$key] ?? '0', (string) ($rows[$d->toDateString()] ?? '0'), 4);
        }

        return array_map(fn ($k, $v) => ['period' => $k, 'quantity' => $v], array_keys($series), $series);
    }
}

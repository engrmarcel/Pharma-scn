<?php

namespace App\Domain\Analytics\Services;

use App\Domain\Analytics\Forecasting\ForecastProvider;
use App\Support\Exceptions\BusinessRuleViolation;

/**
 * Weekly demand forecasts per product (spec §19.2 demand forecasting), from the daily
 * demand facts, within the viewer's locations. Output is a prediction: it carries the
 * model, its backtest accuracy and its assumptions.
 */
class ForecastService
{
    public const MIN_WEEKS = 6;

    public function __construct(private readonly AnalyticsScope $scope, private readonly DemandFacts $demand, private readonly ForecastProvider $provider) {}

    public function forecast(string $productId, ?string $warehouseId, int $horizonWeeks, int $historyWeeks = 52): array
    {
        $this->scope->authorize('inventory.view', 'sales.view', 'procurement.view');

        return $this->build($this->scope->organizationId(), $productId, $this->scope->warehouses($warehouseId), $horizonWeeks, $historyWeeks);
    }

    /** Without permission checks: for the recommendation engine, which runs as the organization. */
    public function build(string $org, string $productId, ?array $warehouses, int $horizonWeeks, int $historyWeeks = 52): array
    {
        $thisWeek = business_today()->startOfWeek();
        $series = $this->demand->series($org, $productId, $warehouses, $thisWeek->copy()->subWeeks($historyWeeks), $thisWeek->copy()->subDay(), 'week');
        // Weeks before the first recorded sale are not demand history.
        while ($series !== [] && bccomp($series[0]['quantity'], '0', 4) === 0) {
            array_shift($series);
        }
        if (count($series) < self::MIN_WEEKS) {
            throw new BusinessRuleViolation('forecast_insufficient_history', 'At least '.self::MIN_WEEKS.' weeks of sales history are needed for a forecast.', 422,
                ['weeks_available' => count($series)]);
        }

        $result = $this->provider->forecast(array_map(fn ($p) => (float) $p['quantity'], $series), $horizonWeeks);

        return [
            'kind' => 'prediction',
            'product_id' => $productId,
            'locations' => $warehouses === null ? 'all' : $warehouses,
            'unit' => 'base units per week',
            'provider' => $this->provider->name(),
            'method' => $result['method'],
            'parameters' => $result['parameters'],
            'history' => $series,
            'forecast' => array_map(fn ($p) => ['week_starting' => $thisWeek->copy()->addWeeks($p['step'] - 1)->toDateString()] + $p, $result['forecast']),
            'accuracy' => $result['accuracy'],
            'assumptions' => $result['assumptions'],
            'demand_data' => $this->demand->lastRefresh($org),
        ];
    }

    /** Expected demand over the next $days days (pro rata from the weekly forecast), or null without enough history. */
    public function expectedDemand(string $org, string $productId, ?array $warehouses, int $days): ?array
    {
        try {
            $f = $this->build($org, $productId, $warehouses, (int) ceil($days / 7));
        } catch (BusinessRuleViolation) {
            return null;
        }
        $total = 0.0;
        $remaining = $days;
        foreach ($f['forecast'] as $week) {
            $total += $week['value'] * min(7, $remaining) / 7;
            $remaining -= 7;
        }

        return ['quantity' => round($total, 2), 'method' => $f['method'], 'provider' => $f['provider'], 'accuracy' => $f['accuracy']];
    }
}

<?php

namespace App\Domain\Analytics\Forecasting;

/**
 * A demand forecasting model (spec §19.2). The built-in provider is statistical and runs
 * locally; an external or machine-learning provider can be bound in its place
 * (config analytics.forecast.provider) as long as it returns the same explainable shape.
 */
interface ForecastProvider
{
    /**
     * @param  list<float>  $history  demand per period, oldest first, complete periods only
     * @return array{method: string, parameters: array, forecast: list<array{step: int, value: float, low: float, high: float}>,
     *               accuracy: array{holdout_periods: int, mae: ?float, wape_percent: ?float}, assumptions: list<string>}
     */
    public function forecast(array $history, int $horizon): array;

    /** Name and version recorded with every forecast and recommendation. */
    public function name(): string;
}

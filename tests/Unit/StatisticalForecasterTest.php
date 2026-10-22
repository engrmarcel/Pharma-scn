<?php

namespace Tests\Unit;

use App\Domain\Analytics\Forecasting\StatisticalForecaster;
use PHPUnit\Framework\TestCase;

class StatisticalForecasterTest extends TestCase
{
    public function test_steady_demand_is_forecast_flat_with_a_perfect_backtest(): void
    {
        $f = (new StatisticalForecaster)->forecast(array_fill(0, 20, 210.0), 4);

        $this->assertSame([210.0, 210.0, 210.0, 210.0], array_column($f['forecast'], 'value'));
        $this->assertSame(['moving_average', 0.0, 0.0], [$f['method'], $f['accuracy']['mae'], $f['accuracy']['wape_percent']]);
        $this->assertSame([210.0, 210.0], [$f['forecast'][0]['low'], $f['forecast'][0]['high']]);
    }

    public function test_a_trend_is_followed_but_damped(): void
    {
        $history = array_map(fn ($i) => 100.0 + 10 * $i, range(0, 19)); // 100 … 290
        $f = (new StatisticalForecaster)->forecast($history, 8);

        $this->assertSame('holt_damped', $f['method']);
        $values = array_column($f['forecast'], 'value');
        $this->assertGreaterThan(290, $values[0]);
        $this->assertLessThan(290 + 8 * 10, $values[7], 'the trend fades instead of running on');
        $this->assertGreaterThan($values[6], $values[7]);
    }

    public function test_intermittent_demand_gets_a_rate_and_a_warning(): void
    {
        $history = [0, 0, 6, 0, 0, 0, 5, 0, 0, 7, 0, 0, 0, 6, 0, 0, 5, 0, 0, 6];
        $f = (new StatisticalForecaster)->forecast($history, 3);

        $this->assertGreaterThan(0, $f['forecast'][0]['value']);
        $this->assertLessThan(5, $f['forecast'][0]['value'], 'an average rate, not a spike');
        $this->assertStringContainsString('intermittent', implode(' ', $f['assumptions']));
    }

    public function test_forecasts_are_never_negative_and_short_histories_are_flagged(): void
    {
        $f = (new StatisticalForecaster)->forecast([90, 70, 50, 30, 10, 0], 6);

        foreach ($f['forecast'] as $point) {
            $this->assertGreaterThanOrEqual(0, $point['low']);
            $this->assertGreaterThanOrEqual(0, $point['value']);
        }
        $this->assertStringContainsString('Only 6 periods', implode(' ', $f['assumptions']));
    }
}

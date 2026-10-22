<?php

namespace App\Domain\Analytics\Forecasting;

/**
 * Local statistical forecasting: a few transparent models, chosen per series by a
 * rolling one-step-ahead backtest on the most recent periods.
 *
 *   moving_average   mean of the last 4 periods (the baseline every model must beat)
 *   ses              simple exponential smoothing (level only)
 *   holt_damped      level and damped trend (trend fades, so it never runs away)
 *   croston_sba      intermittent demand (many zero periods): size and interval smoothed separately, bias-corrected
 *
 * Intervals are approximate 80% bands from the backtest errors, widening with the step.
 * Forecasts are never negative.
 */
class StatisticalForecaster implements ForecastProvider
{
    private const GRID = [0.1, 0.2, 0.3, 0.5, 0.7];

    private const DAMPING = 0.9;

    private const Z80 = 1.2816;

    public function name(): string
    {
        return 'statistical-v1';
    }

    public function forecast(array $history, int $horizon): array
    {
        $history = array_values(array_map('floatval', $history));
        $n = count($history);
        $zeroShare = $n > 0 ? count(array_filter($history, fn ($v) => $v <= 0.0)) / $n : 1.0;
        $holdout = max(1, min(8, intdiv($n, 3)));

        $candidates = [['moving_average', []]];
        foreach (self::GRID as $a) {
            $candidates[] = ['ses', ['alpha' => $a]];
            foreach ([0.1, 0.3] as $b) {
                $candidates[] = ['holt_damped', ['alpha' => $a, 'beta' => $b, 'phi' => self::DAMPING]];
            }
            if ($zeroShare >= 0.3) {
                $candidates[] = ['croston_sba', ['alpha' => $a]];
            }
        }

        $best = null;
        foreach ($candidates as [$method, $params]) {
            $errors = [];
            for ($t = $n - $holdout; $t < $n; $t++) {
                if ($t < 2) {
                    continue;
                }
                $errors[] = $history[$t] - $this->fit($method, $params, array_slice($history, 0, $t), 1)[0];
            }
            $mae = $errors ? array_sum(array_map('abs', $errors)) / count($errors) : INF;
            // Prefer the simpler model on ties (candidates are ordered from simple to complex).
            if ($best === null || $mae < $best['mae'] - 1e-9) {
                $best = ['method' => $method, 'params' => $params, 'mae' => $mae, 'errors' => $errors];
            }
        }

        $points = $this->fit($best['method'], $best['params'], $history, $horizon);
        $errors = $best['errors'];
        $rmse = $errors ? sqrt(array_sum(array_map(fn ($e) => $e * $e, $errors)) / count($errors)) : 0.0;
        $actual = array_sum(array_slice($history, -count($errors) ?: $n));

        return [
            'method' => $best['method'],
            'parameters' => $best['params'],
            'forecast' => array_map(fn ($v, $i) => ['step' => $i + 1, 'value' => round(max(0.0, $v), 2),
                'low' => round(max(0.0, $v - self::Z80 * $rmse * sqrt($i + 1)), 2), 'high' => round(max(0.0, $v + self::Z80 * $rmse * sqrt($i + 1)), 2)], $points, array_keys($points)),
            'accuracy' => ['holdout_periods' => count($errors), 'mae' => $errors ? round($best['mae'], 2) : null,
                'wape_percent' => $errors && $actual > 0 ? round(array_sum(array_map('abs', $errors)) / $actual * 100, 1) : null],
            'assumptions' => array_values(array_filter([
                'Future demand follows the pattern of recorded sales; promotions, stockouts and new competitors are not known to the model.',
                'Periods with no stock still count as zero demand, which can understate true demand.',
                $zeroShare >= 0.3 ? 'Demand is intermittent ('.round($zeroShare * 100).'% of periods with no sales); forecasts are average rates, not exact quantities per period.' : null,
                $n < 12 ? "Only {$n} periods of history; the forecast is less reliable." : null,
                'Intervals are approximate 80% ranges from the backtest errors.',
            ])),
        ];
    }

    /** @return list<float> forecasts for the next $horizon periods after $series */
    private function fit(string $method, array $p, array $series, int $horizon): array
    {
        $n = count($series);
        if ($n === 0) {
            return array_fill(0, $horizon, 0.0);
        }

        switch ($method) {
            case 'moving_average':
                $window = array_slice($series, -4);

                return array_fill(0, $horizon, array_sum($window) / count($window));

            case 'ses':
                $level = $series[0];
                foreach (array_slice($series, 1) as $y) {
                    $level = $p['alpha'] * $y + (1 - $p['alpha']) * $level;
                }

                return array_fill(0, $horizon, $level);

            case 'holt_damped':
                $level = $series[0];
                $trend = $n > 1 ? $series[1] - $series[0] : 0.0;
                foreach (array_slice($series, 1) as $y) {
                    $previous = $level;
                    $level = $p['alpha'] * $y + (1 - $p['alpha']) * ($level + $p['phi'] * $trend);
                    $trend = $p['beta'] * ($level - $previous) + (1 - $p['beta']) * $p['phi'] * $trend;
                }
                $out = [];
                $damp = 0.0;
                for ($h = 1; $h <= $horizon; $h++) {
                    $damp += $p['phi'] ** $h;
                    $out[] = $level + $damp * $trend;
                }

                return $out;

            case 'croston_sba':
                $size = null;
                $interval = null;
                $since = 1;
                foreach ($series as $y) {
                    if ($y > 0) {
                        $size = $size === null ? $y : $p['alpha'] * $y + (1 - $p['alpha']) * $size;
                        $interval = $interval === null ? $since : $p['alpha'] * $since + (1 - $p['alpha']) * $interval;
                        $since = 1;
                    } else {
                        $since++;
                    }
                }
                $rate = $size === null ? 0.0 : (1 - $p['alpha'] / 2) * $size / max(1.0, $interval);

                return array_fill(0, $horizon, $rate);
        }

        throw new \LogicException("Unknown forecasting method {$method}.");
    }
}

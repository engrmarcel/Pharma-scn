<?php

namespace App\Domain\Analytics\Support;

use Illuminate\Support\Carbon;

/** Small formatting rules shared by every dashboard: exact decimals, money per currency, trend buckets. */
final class Figures
{
    /** SUM() of decimals comes back with extra scale or as null; normalise without going through floats. */
    public static function dec($value, int $scale = 4): string
    {
        return bcadd((string) ($value ?? '0'), '0', $scale);
    }

    /**
     * Money is never added across currencies.
     *
     * @param  iterable<object>  $rows  each with currency_code and the amount field
     * @return list<array{currency_code: string, amount: string}>
     */
    public static function money(iterable $rows, string $field = 'amount'): array
    {
        $totals = [];
        foreach ($rows as $r) {
            $totals[$r->currency_code] = bcadd($totals[$r->currency_code] ?? '0', (string) ($r->{$field} ?? '0'), 4);
        }
        ksort($totals);

        return array_map(fn ($c, $a) => ['currency_code' => $c, 'amount' => $a], array_keys($totals), $totals);
    }

    public static function percent(int|float $part, int|float $whole): ?float
    {
        return $whole > 0 ? round($part / $whole * 100, 1) : null;
    }

    /** Trend granularity: daily up to two months, weekly up to a year, then monthly. */
    public static function bucket(Carbon $from, Carbon $to): string
    {
        $days = $from->diffInDays($to);

        return $days <= 62 ? 'day' : ($days <= 366 ? 'week' : 'month');
    }

    /** SQL expression for the bucket of a datetime column (MySQL and MariaDB). */
    public static function bucketSql(string $bucket, string $column): string
    {
        return match ($bucket) {
            'day' => "DATE({$column})",
            'week' => "DATE(DATE_SUB({$column}, INTERVAL WEEKDAY({$column}) DAY))",
            'month' => "DATE_FORMAT({$column}, '%Y-%m')",
        };
    }

    /** A section the viewer may not see: shown as withheld with the reason, never as zero. */
    public static function withheld(string $reason): array
    {
        return ['withheld' => true, 'reason' => $reason];
    }
}

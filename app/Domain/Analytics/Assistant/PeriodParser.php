<?php

namespace App\Domain\Analytics\Assistant;

use Illuminate\Support\Carbon;

/** Reads a reporting period from plain English ("last month", "past 30 days", "in March", "2026-01-01 to 2026-03-31"). */
final class PeriodParser
{
    private const MONTHS = ['january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december'];

    /** @return array{0: string, 1: string, 2: string}|null from, to, label */
    public static function parse(string $question, Carbon $today): ?array
    {
        $q = mb_strtolower($question);
        $t = $today->copy()->startOfDay();
        $range = fn (Carbon $a, Carbon $b, string $label) => [$a->toDateString(), min($b, $t)->toDateString(), $label];

        if (preg_match('/(\d{4}-\d{2}-\d{2})\s*(?:to|until|-|and)\s*(\d{4}-\d{2}-\d{2})/', $q, $m)) {
            return [$m[1], $m[2], "{$m[1]} to {$m[2]}"];
        }
        if (preg_match('/since\s+(\d{4}-\d{2}-\d{2})/', $q, $m)) {
            return [$m[1], $t->toDateString(), "since {$m[1]}"];
        }
        if (preg_match('/(?:last|past|previous)\s+(\d{1,3})\s+(day|week|month)s?/', $q, $m)) {
            $start = match ($m[2]) {
                'day' => $t->copy()->subDays((int) $m[1] - 1),
                'week' => $t->copy()->subWeeks((int) $m[1])->addDay(),
                'month' => $t->copy()->subMonths((int) $m[1])->addDay(),
            };

            return $range($start, $t, "the last {$m[1]} {$m[2]}s");
        }

        return match (true) {
            str_contains($q, 'today') => $range($t, $t, 'today'),
            str_contains($q, 'yesterday') => $range($t->copy()->subDay(), $t->copy()->subDay(), 'yesterday'),
            (bool) preg_match('/(last|previous) week/', $q) => $range($t->copy()->subWeek()->startOfWeek(), $t->copy()->subWeek()->endOfWeek(), 'last week'),
            str_contains($q, 'this week') => $range($t->copy()->startOfWeek(), $t, 'this week'),
            (bool) preg_match('/(last|previous) month/', $q) => $range($t->copy()->subMonthNoOverflow()->startOfMonth(), $t->copy()->subMonthNoOverflow()->endOfMonth(), 'last month'),
            str_contains($q, 'this month') => $range($t->copy()->startOfMonth(), $t, 'this month'),
            (bool) preg_match('/(last|previous) year/', $q) => $range($t->copy()->subYear()->startOfYear(), $t->copy()->subYear()->endOfYear(), 'last year'),
            (bool) preg_match('/this year|year to date|ytd/', $q) => $range($t->copy()->startOfYear(), $t, 'this year'),
            (bool) preg_match('/\b(this|last|past|current) quarter\b/', $q, $m) => $m[1] === 'last' || $m[1] === 'past'
                ? $range($t->copy()->subQuarter()->firstOfQuarter(), $t->copy()->subQuarter()->lastOfQuarter(), 'last quarter')
                : $range($t->copy()->firstOfQuarter(), $t, 'this quarter'),
            default => self::month($q, $t),
        };
    }

    private static function month(string $q, Carbon $t): ?array
    {
        foreach (self::MONTHS as $i => $name) {
            if (preg_match('/\b(?:in|for|during)\s+'.$name.'\b(?:\s+(\d{4}))?/', $q, $m)) {
                $year = isset($m[1]) ? (int) $m[1] : ($i + 1 > $t->month ? $t->year - 1 : $t->year);
                $start = Carbon::create($year, $i + 1, 1)->startOfDay();

                return [$start->toDateString(), min($start->copy()->endOfMonth(), $t)->toDateString(), ucfirst($name)." {$year}"];
            }
        }

        return null;
    }
}

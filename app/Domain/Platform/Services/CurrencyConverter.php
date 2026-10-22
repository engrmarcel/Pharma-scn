<?php

namespace App\Domain\Platform\Services;

use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Converts platform-wide totals into one reporting currency for presentation (spec §20
 * country-aware reports). Rates are recorded by platform staff with a source and an
 * effective date; the latest rate on or before the as-of date is used, directly or
 * inverted. Without a rate an amount is left unconverted and listed, never guessed.
 * Tenant books are never converted.
 */
class CurrencyConverter
{
    /** @return array{rate: string, effective_date: string, source: string}|null quote units per 1 base unit */
    public function rate(string $from, string $to, string $asOf): ?array
    {
        if ($from === $to) {
            return ['rate' => '1', 'effective_date' => $asOf, 'source' => 'same currency'];
        }
        $direct = DB::table('exchange_rates')->where('base_currency', $from)->where('quote_currency', $to)->where('effective_date', '<=', $asOf)
            ->orderByDesc('effective_date')->first(['rate', 'effective_date', 'source']);
        if ($direct) {
            return ['rate' => (string) $direct->rate, 'effective_date' => (string) $direct->effective_date, 'source' => $direct->source];
        }
        $inverse = DB::table('exchange_rates')->where('base_currency', $to)->where('quote_currency', $from)->where('effective_date', '<=', $asOf)
            ->orderByDesc('effective_date')->first(['rate', 'effective_date', 'source']);

        return $inverse && bccomp((string) $inverse->rate, '0', 8) > 0
            ? ['rate' => bcdiv('1', (string) $inverse->rate, 12), 'effective_date' => (string) $inverse->effective_date, 'source' => $inverse->source.' (inverted)']
            : null;
    }

    /**
     * @param  list<array{currency_code: string, amount: string}>  $money
     * @return array{currency_code: string, amount: string, rates: list<array>, unconverted: list<array>}
     */
    public function total(array $money, string $to, string $asOf): array
    {
        $sum = '0';
        $rates = [];
        $unconverted = [];
        foreach ($money as $m) {
            $rate = $this->rate($m['currency_code'], $to, $asOf);
            if ($rate === null) {
                $unconverted[] = $m;

                continue;
            }
            $sum = bcadd($sum, bcmul($m['amount'], $rate['rate'], 12), 12);
            if ($m['currency_code'] !== $to) {
                $rates[] = ['from' => $m['currency_code'], 'to' => $to] + $rate;
            }
        }

        return ['currency_code' => $to, 'amount' => Money::forCurrency($sum, $to), 'rates' => $rates, 'unconverted' => $unconverted];
    }
}

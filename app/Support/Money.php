<?php

namespace App\Support;

/**
 * Decimal arithmetic for money and quantities (never floats). Amounts are
 * strings with 4 decimal places; intermediate results keep 10 places and
 * are rounded half-up (away from zero).
 */
final class Money
{
    public const SCALE = 4;

    public static function round(string $value, int $scale = self::SCALE): string
    {
        $offset = '0.'.str_repeat('0', $scale).'5';
        $rounded = bccomp($value, '0', 10) >= 0 ? bcadd($value, $offset, $scale) : bcsub($value, $offset, $scale);

        return bcadd($rounded, '0', $scale);
    }

    /** Decimal places of the currency's smallest unit (config/currencies.php; 2 when unknown). */
    public static function minorUnits(string $currency): int
    {
        return (int) config("currencies.{$currency}.minor_units", 2);
    }

    /** Rounded to the currency's smallest unit (half-up), kept in the 4-place storage format. */
    public static function forCurrency(string $value, string $currency): string
    {
        return bcadd(self::round($value, self::minorUnits($currency)), '0', self::SCALE);
    }

    public static function mul(string $a, string $b): string
    {
        return self::round(bcmul($a, $b, 10));
    }

    public static function add(string ...$values): string
    {
        $sum = '0';
        foreach ($values as $v) {
            $sum = bcadd($sum, $v, 10);
        }

        return self::round($sum);
    }

    /** @return array{0: string, 1: string} [subtotal, tax] for quantity × unit price at a percent tax rate, rounded to the currency when given */
    public static function line(string $quantity, string $unitPrice, string $taxRatePercent = '0', ?string $currency = null): array
    {
        $round = fn (string $v) => $currency ? self::forCurrency($v, $currency) : self::round($v);
        $subtotal = $round(bcmul($quantity, $unitPrice, 10));
        $tax = $round(bcdiv(bcmul($subtotal, $taxRatePercent, 10), '100', 10));

        return [$subtotal, $tax];
    }
}

<?php

namespace App\Domain\Catalogue\Support;

/**
 * GS1 GTIN-8/12/13/14 structure and check-digit validation. A valid check
 * digit says nothing about whether the GTIN is allocated to this product.
 */
final class Gtin
{
    public static function isValid(string $value): bool
    {
        if (! preg_match('/^\d{8}$|^\d{12,14}$/', $value)) {
            return false;
        }

        $digits = str_split(str_pad($value, 14, '0', STR_PAD_LEFT));
        $check = (int) array_pop($digits);
        $sum = 0;
        foreach ($digits as $i => $digit) {
            $sum += (int) $digit * ($i % 2 === 0 ? 3 : 1);
        }

        return (10 - $sum % 10) % 10 === $check;
    }
}

<?php

namespace App\Domain\Catalogue\Support;

use App\Support\Exceptions\BusinessRuleViolation;

/**
 * Validates a product's packaging levels and derives how many base units each
 * level contains. Levels must be contiguous from 0 (the smallest unit); every
 * level above 0 states how many of the level directly below it holds.
 *
 *   level 0 tablet            -> base 1
 *   level 1 blister  x10      -> base 10
 *   level 2 pack     x3       -> base 30
 *   level 3 carton   x50      -> base 1500
 */
final class PackageHierarchy
{
    /**
     * @param  array<int, array<string, mixed>>  $levels
     * @return array<int, array<string, mixed>> levels sorted, with base_unit_quantity
     */
    public static function build(array $levels): array
    {
        if ($levels === []) {
            throw new BusinessRuleViolation('package_hierarchy_invalid', 'At least the base unit (level 0) is required.');
        }

        usort($levels, fn ($a, $b) => (int) $a['level'] <=> (int) $b['level']);

        $base = '1';
        foreach ($levels as $index => &$level) {
            if ((int) $level['level'] !== $index) {
                throw new BusinessRuleViolation('package_hierarchy_invalid', 'Package levels must be contiguous starting at 0.');
            }

            if ($index === 0) {
                $level['quantity_of_child'] = null;
            } else {
                $quantity = (string) ($level['quantity_of_child'] ?? '0');
                if (bccomp($quantity, '0', 4) <= 0) {
                    throw new BusinessRuleViolation('package_hierarchy_invalid', "Level {$index} must contain a positive quantity of level ".($index - 1).'.');
                }
                $base = bcmul($base, $quantity, 4);
            }

            $level['base_unit_quantity'] = $base;
        }

        return $levels;
    }

    /**
     * Convert a quantity between two levels of the same product, e.g. 2 cartons to tablets.
     */
    public static function convert(string $quantity, string $fromBaseUnits, string $toBaseUnits, int $scale = 4): string
    {
        return bcdiv(bcmul($quantity, $fromBaseUnits, 8), $toBaseUnits, $scale);
    }
}

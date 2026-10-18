<?php

namespace Tests\Unit;

use App\Domain\Catalogue\Support\Gtin;
use App\Domain\Catalogue\Support\PackageHierarchy;
use App\Support\Exceptions\BusinessRuleViolation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CatalogueSupportTest extends TestCase
{
    public static function gtins(): array
    {
        return [
            'valid GTIN-13' => ['4006381333931', true],
            'valid GTIN-14' => ['06291041500213', true],
            'valid GTIN-8' => ['96385074', true],
            'valid GTIN-12' => ['036000291452', true],
            'bad check digit' => ['4006381333932', false],
            'letters' => ['40063813339A1', false],
            'wrong length' => ['12345', false],
        ];
    }

    #[DataProvider('gtins')]
    public function test_gtin_validation(string $value, bool $valid): void
    {
        $this->assertSame($valid, Gtin::isValid($value));
    }

    public function test_hierarchy_derives_base_units_from_unordered_input(): void
    {
        $levels = PackageHierarchy::build([
            ['level' => 2, 'code' => 'pack', 'quantity_of_child' => '3'],
            ['level' => 0, 'code' => 'tablet'],
            ['level' => 1, 'code' => 'blister', 'quantity_of_child' => '10'],
        ]);

        $this->assertSame(['1', '10.0000', '30.0000'], array_column($levels, 'base_unit_quantity'));
        $this->assertNull($levels[0]['quantity_of_child']);
    }

    public function test_hierarchy_supports_fractional_volumes(): void
    {
        $levels = PackageHierarchy::build([
            ['level' => 0, 'code' => 'ml'],
            ['level' => 1, 'code' => 'bottle', 'quantity_of_child' => '100.5'],
        ]);

        $this->assertSame('100.5000', $levels[1]['base_unit_quantity']);
    }

    public function test_conversion_between_levels(): void
    {
        // 2 cartons of 1500 tablets = 300 blisters of 10.
        $this->assertSame('300.0000', PackageHierarchy::convert('2', '1500', '10'));
    }

    public function test_hierarchy_rejects_missing_base_level(): void
    {
        $this->expectException(BusinessRuleViolation::class);
        PackageHierarchy::build([['level' => 1, 'code' => 'pack', 'quantity_of_child' => '10']]);
    }
}

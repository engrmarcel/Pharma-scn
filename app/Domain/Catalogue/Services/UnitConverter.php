<?php

namespace App\Domain\Catalogue\Services;

use App\Domain\Catalogue\Models\OrganizationProduct;
use App\Domain\Catalogue\Models\ProductPackageLevel;
use App\Support\Exceptions\BusinessRuleViolation;
use App\Support\Tenancy\TenantContext;

/**
 * Converts quantities entered at any package level of a product into base
 * units, for products in the current organization's catalogue.
 */
class UnitConverter
{
    /** @var array<string, OrganizationProduct|null> keyed by tenant + product, never shared across organizations */
    private array $items = [];

    public function __construct(private readonly TenantContext $tenant) {}

    public function catalogueItem(string $productId): OrganizationProduct
    {
        $key = $this->tenant->id().'|'.$productId;
        $item = $this->items[$key] ??= OrganizationProduct::query()->with('product.packageLevels')->where('product_id', $productId)->first();

        return $item ?? throw new BusinessRuleViolation('product_not_in_catalogue', 'Add the product to your catalogue first.', 422, ['product_id' => $productId]);
    }

    public function level(string $productId, ?string $code): ProductPackageLevel
    {
        $levels = $this->catalogueItem($productId)->product->packageLevels;
        $level = $code === null ? $levels->firstWhere('level', 0) : $levels->firstWhere('code', $code);

        return $level ?? throw new BusinessRuleViolation('package_level_unknown', "Unknown package level '{$code}' for this product.", 422, ['product_id' => $productId]);
    }

    public function toBase(string $productId, ?string $code, string $quantity): string
    {
        return bcmul($quantity, (string) $this->level($productId, $code)->base_unit_quantity, 4);
    }
}

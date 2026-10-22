<?php

namespace App\Domain\Sales\Services;

use App\Domain\Catalogue\Services\UnitConverter;
use App\Domain\Organizations\Models\Warehouse;
use App\Domain\Parties\Models\Customer;
use App\Domain\Platform\Support\CountryProfile;
use App\Domain\Sales\Models\PriceList;
use App\Domain\Sales\Models\PriceListItem;
use App\Domain\Sales\Models\TaxRate;
use App\Support\Exceptions\BusinessRuleViolation;
use App\Support\Money;
use App\Support\Tenancy\TenantContext;

/**
 * Price and tax resolution for sales lines.
 *
 * Price: customer's price list → branch price list → default price list →
 *        catalogue default selling price (per base unit × package size).
 * Tax:   the catalogue item's tax category (or the country default category)
 *        → the tax rate effective on the document date. No rate is assumed
 *        when none is configured; the line is refused instead.
 */
class SalesPricing
{
    public function __construct(
        private readonly UnitConverter $units,
        private readonly TenantContext $tenant,
    ) {}

    /** @return array{price: string, source: string} */
    public function listPrice(?Customer $customer, Warehouse $warehouse, string $productId, string $levelCode, string $quantity, string $date): array
    {
        $candidates = array_filter([
            'customer_price_list' => $customer?->price_list_id,
            'branch_price_list' => $warehouse->branch_id ? PriceList::query()->where('branch_id', $warehouse->branch_id)->where('status', 'active')->value('id') : null,
            'default_price_list' => PriceList::query()->where('is_default', true)->where('status', 'active')->value('id'),
        ]);

        foreach ($candidates as $source => $listId) {
            $price = PriceListItem::query()->where('price_list_id', $listId)->where('product_id', $productId)->where('package_level_code', $levelCode)
                ->where('minimum_quantity', '<=', $quantity)
                ->where(fn ($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $date))
                ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $date))
                ->orderByDesc('minimum_quantity')->value('unit_price');
            if ($price !== null) {
                return ['price' => (string) $price, 'source' => $source];
            }
        }

        $item = $this->units->catalogueItem($productId);
        if ($item->default_selling_price !== null) {
            $level = $this->units->level($productId, $levelCode);

            return ['price' => Money::mul((string) $item->default_selling_price, (string) $level->base_unit_quantity), 'source' => 'catalogue_default'];
        }

        throw new BusinessRuleViolation('price_required', 'No price list entry or catalogue selling price for this product.', 422, ['product_id' => $productId]);
    }

    /** @return array{category: string, rate: string} */
    public function tax(string $productId, string $date): array
    {
        $organization = $this->tenant->organization();
        $category = $this->units->catalogueItem($productId)->tax_category_code
            ?? $organization->country->setting('tax.default_category')
            ?? throw new BusinessRuleViolation('tax_category_missing', 'Set a tax category on the catalogue item (no country default is configured).', 422, ['product_id' => $productId]);

        $rate = TaxRate::query()->where('country_code', $organization->country_code)->where('category_code', $category)
            ->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date))
            ->orderByDesc('effective_from')->value('rate');

        if ($rate === null) {
            throw new BusinessRuleViolation('tax_rate_missing', "No {$category} tax rate is configured for {$organization->country_code} on {$date}.", 422);
        }

        return ['category' => $category, 'rate' => (string) $rate];
    }

    /** Whether shelf prices include tax in the organization's country (tax.prices_include_tax). */
    public function pricesIncludeTax(): bool
    {
        return (bool) CountryProfile::of($this->tenant->organization()->country)->get('tax.prices_include_tax');
    }

    /**
     * Line amounts. Discount applies before tax; tax applies to the discounted amount.
     * With a currency, each amount is rounded to its smallest unit (line-level rounding).
     *
     * Tax-inclusive prices (countries where shelf prices include tax): the unit price and
     * discount include tax; the stored subtotal and discount are the ex-tax shares, so that
     * subtotal − discount + tax = total = the price paid, and revenue and tax post correctly.
     *
     * @return array{subtotal: string, discount: string, tax: string, total: string}
     */
    public static function amounts(string $quantity, string $unitPrice, string $discountPercent, string $taxRate, ?string $currency = null, bool $taxInclusive = false): array
    {
        $round = fn (string $v) => $currency ? Money::forCurrency($v, $currency) : Money::round($v);
        $gross = $round(bcmul($quantity, $unitPrice, 10));
        $discount = $round(bcdiv(bcmul($gross, $discountPercent, 10), '100', 10));

        if (! $taxInclusive) {
            $net = bcsub($gross, $discount, 4);
            $tax = $round(bcdiv(bcmul($net, $taxRate, 10), '100', 10));

            return ['subtotal' => $gross, 'discount' => $discount, 'tax' => $tax, 'total' => bcadd($net, $tax, 4)];
        }

        $total = bcsub($gross, $discount, 4);
        $divisor = bcadd('1', bcdiv($taxRate, '100', 10), 10);
        $subtotal = $round(bcdiv($gross, $divisor, 10));
        $discountExTax = $round(bcdiv($discount, $divisor, 10));
        $tax = bcsub($total, bcsub($subtotal, $discountExTax, 4), 4);

        return ['subtotal' => $subtotal, 'discount' => $discountExTax, 'tax' => $tax, 'total' => $total];
    }
}

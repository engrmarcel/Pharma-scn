<?php

namespace Tests\Concerns;

use App\Domain\Catalogue\Models\OrganizationProduct;
use App\Domain\Catalogue\Models\Product;
use App\Domain\Catalogue\Models\ProductClassification;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationModule;
use App\Domain\Organizations\Models\Warehouse;

/** Organizations taking part in the marketplace, a stocked supplier and a prescription-only product. */
trait InteractsWithMarketplace
{
    use InteractsWithInventory;

    protected function marketplaceOrg(string $type, bool $verified = true): array
    {
        [$owner, $org, $membership] = $this->organizationMember(orgAttributes: ['type' => $type, 'country_code' => 'NG'], verified: $verified);
        $this->enableMarketplace($org);

        return [$owner, $org->fresh(), $membership];
    }

    /** A verified national distributor with Amoxicillin (OTC here) and Ceftriaxone (prescription-only), both in stock. */
    protected function stockedSupplier(bool $verified = true): array
    {
        [$owner, $org] = $this->marketplaceOrg('national_distributor', $verified);
        $amox = $this->makeProduct(['brand_name' => 'Supplimox']);
        $rx = $this->makeProduct(['generic_name' => 'Ceftriaxone', 'brand_name' => 'Rocetrix', 'strength_text' => '1 g', 'dosage_form_code' => 'injection',
            'ingredients' => [['name' => 'Ceftriaxone sodium', 'strength_value' => 1, 'strength_unit' => 'g']]]);
        ProductClassification::query()->create(['product_id' => $rx->id, 'country_code' => 'NG', 'classification' => 'prescription_only']);

        $main = $this->inTenant($org, function () use ($amox, $rx) {
            foreach ([[$amox, 'AMX'], [$rx, 'CEF']] as [$product, $sku]) {
                OrganizationProduct::query()->create(['product_id' => $product->id, 'sku' => $sku, 'currency_code' => 'NGN', 'tax_category_code' => 'EXEMPT']);
            }

            return Warehouse::query()->create(['code' => 'MAIN', 'name' => 'Main']);
        });
        $this->receive($org, $main, $amox, 'MP-AMX-1', '2000', '+2 years', ['unit_cost' => '40']);
        $this->receive($org, $main, $rx, 'MP-CEF-1', '500', '+2 years', ['unit_cost' => '900']);

        return compact('owner', 'org', 'amox', 'rx', 'main');
    }

    /** organization_modules has no primary key, so update with an explicit where (not updateOrCreate). */
    protected function enableMarketplace(Organization $org, bool $enabled = true): void
    {
        $row = OrganizationModule::query()->where('organization_id', $org->id)->where('module', 'marketplace');
        $row->exists()
            ? $row->update(['enabled' => $enabled])
            : OrganizationModule::query()->create(['organization_id' => $org->id, 'module' => 'marketplace', 'enabled' => $enabled]);
    }

    protected function listing(array $s, Product $product, array $extra = []): string
    {
        return $this->actingAsApi($s['owner'], $s['org'])->api('POST', 'marketplace/listings', $extra + [
            'product_id' => $product->id, 'package_level_code' => 'pack', 'unit_price' => '1200', 'minimum_order_quantity' => '10',
        ])->assertCreated()->json('data.id');
    }
}

<?php

namespace Tests\Concerns;

use App\Domain\Catalogue\Models\OrganizationProduct;
use App\Domain\Catalogue\Models\Product;
use App\Domain\Inventory\Models\Batch;
use App\Domain\Inventory\Models\InventoryBalance;
use App\Domain\Inventory\Services\StockReceiptService;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Organizations\Models\Warehouse;
use App\Support\Tenancy\TenantContext;

trait InteractsWithInventory
{
    protected function stockedOrganization(): array
    {
        [$owner, $org, $membership] = $this->organizationMember(orgAttributes: ['type' => 'pharmacy']);
        $product = $this->makeProduct(); // capsule → blister(10) → pack(20) → carton(1000)

        [$main, $annex] = $this->inTenant($org, function () use ($product) {
            OrganizationProduct::query()->create(['product_id' => $product->id, 'sku' => 'AMX', 'currency_code' => 'NGN']);

            return [
                Warehouse::query()->create(['code' => 'MAIN', 'name' => 'Main']),
                Warehouse::query()->create(['code' => 'ANNEX', 'name' => 'Annex']),
            ];
        });

        return [$owner, $org, $product, $main, $annex, $membership];
    }

    /** Receive base units directly through the service, as the given organization. */
    protected function receive(Organization $org, Warehouse $warehouse, Product $product, string $batch, string $quantity, ?string $expiry = '+1 year', array $extra = []): Batch
    {
        return $this->inTenant($org, function () use ($warehouse, $product, $batch, $quantity, $expiry, $extra) {
            $this->asTenantMember($org = app(TenantContext::class)->organization());
            app(StockReceiptService::class)->receive($warehouse, [[
                'product_id' => $product->id,
                'batch_number' => $batch,
                'expiry_date' => $expiry ? now()->modify($expiry)->toDateString() : null,
                'quantity' => $quantity,
            ] + $extra]);

            return Batch::query()->where('batch_number', strtoupper($batch))->firstOrFail();
        });
    }

    protected function balance(Organization $org, Warehouse $warehouse, Batch $batch, string $state = 'available'): array
    {
        $row = $this->inTenant($org, fn () => InventoryBalance::query()->where('warehouse_id', $warehouse->id)->where('batch_id', $batch->id)->where('stock_state', $state)->first());

        return [(string) ($row?->quantity ?? '0.0000'), (string) ($row?->reserved_quantity ?? '0.0000')];
    }

    /** Service calls outside HTTP need a membership in context for location checks. */
    private function asTenantMember(Organization $org): void
    {
        $context = app(TenantContext::class);
        if ($context->membership() === null) {
            $context->set($org, OrganizationMembership::query()->where('organization_id', $org->id)->first());
        }
    }
}

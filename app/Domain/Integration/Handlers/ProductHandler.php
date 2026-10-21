<?php

namespace App\Domain\Integration\Handlers;

use App\Domain\Catalogue\Models\OrganizationProduct;
use App\Domain\Integration\Models\IntegrationConnector;
use App\Domain\Integration\Models\IntegrationMessage;
use App\Domain\Integration\Services\ReferenceResolver;
use App\Domain\Platform\Services\AuditLogger;
use App\Support\Exceptions\BusinessRuleViolation;
use App\Support\Tenancy\TenantContext;

/**
 * Canonical product → the organization's catalogue item for a Product Master
 * product. The shared Product Master itself is never created or edited from
 * an integration; unmatched products are quarantined for a person to map or
 * to propose through catalogue governance.
 */
class ProductHandler implements IntegrationHandler
{
    public function __construct(
        private readonly ReferenceResolver $resolver,
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(IntegrationMessage $message, IntegrationConnector $connector, array $data): array
    {
        [$product, $matchedBy] = $this->resolver->productForProductRecord($connector, $data);
        $this->resolver->remember($connector, 'product', $data['external_id'], 'product', $product->id);

        $attributes = array_filter([
            'local_name' => $data['name'],
            'default_purchase_cost' => $data['purchase_cost'] ?? null,
            'default_selling_price' => $data['selling_price'] ?? null,
            'currency_code' => isset($data['currency_code']) ? strtoupper($data['currency_code']) : null,
            'reorder_level' => $data['reorder_level'] ?? null,
            'status' => $data['status'] ?? null,
        ], fn ($v) => $v !== null);

        $item = OrganizationProduct::query()->where('product_id', $product->id)->lockForUpdate()->first();
        $sku = $data['sku'] ?? ($item === null ? $data['external_id'] : null);
        if ($sku !== null && $sku !== $item?->sku) {
            $taken = OrganizationProduct::query()->where('sku', $sku)->when($item, fn ($q) => $q->whereKeyNot($item->id))->first();
            if ($taken !== null) {
                throw new BusinessRuleViolation('sku_conflict', "SKU {$sku} is already used by another product in your catalogue.", 409,
                    ['sku' => $sku, 'organization_product_id' => $taken->id]);
            }
            $attributes['sku'] = $sku;
        }

        if ($item === null) {
            $attributes['currency_code'] ??= $this->tenant->organization()->country?->currency_code;
            $item = OrganizationProduct::query()->create(['product_id' => $product->id] + $attributes);
            $action = 'created';
        } else {
            $item->fill($attributes);
            $action = $item->isDirty() ? 'updated' : 'unchanged';
            $item->save();
        }

        if ($action !== 'unchanged') {
            $this->audit->record("integration.catalogue_item.{$action}", $item, new: $attributes,
                metadata: ['connector_id' => $connector->id, 'message_id' => $message->message_id]);
        }

        return ['action' => $action, 'product_id' => $product->id, 'organization_product_id' => $item->id, 'sku' => $item->sku, 'matched_by' => $matchedBy];
    }
}

<?php

namespace App\Domain\Integration\Services;

use App\Domain\Catalogue\Enums\ProductStatus;
use App\Domain\Catalogue\Models\Product;
use App\Domain\Catalogue\Models\ProductIdentifier;
use App\Domain\Catalogue\Models\RegulatoryIdentifier;
use App\Domain\Catalogue\Support\Gtin;
use App\Domain\Integration\Models\ExternalReference;
use App\Domain\Integration\Models\IntegrationConnector;
use App\Domain\Organizations\Models\Warehouse;
use App\Support\Exceptions\BusinessRuleViolation;

/**
 * Resolves the source system's identifiers to our records (spec §15.2: an
 * external product code is never assumed to be our product id).
 *
 * A product record resolves through an external reference recorded for this
 * connector, its GTIN and its regulatory registration number. Every signal
 * present must agree; disagreement is quarantined, because a GTIN and a
 * registration number pointing at different products is either a data error
 * or a counterfeit signal.
 */
class ReferenceResolver
{
    /** @return array{0: Product, 1: string} the product and what matched it */
    public function productForProductRecord(IntegrationConnector $connector, array $data): array
    {
        $candidates = array_filter([
            'reference' => $this->reference($connector, 'product', $data['external_id'])?->internal_id,
            'gtin' => empty($data['gtin']) ? null : $this->byGtin($data['gtin']),
            'registration_number' => empty($data['registration_number']) ? null : $this->byRegistration($data['registration_number'], $data['registration_authority'] ?? null),
        ]);

        if ($candidates === []) {
            throw new BusinessRuleViolation('product_unmatched',
                'No product in the Product Master matches this record. Map it to an existing product (POST /integration/messages/{id}/map with product_id), '
                .'or propose the product to the catalogue first and then retry.', 422, [
                    'external_id' => $data['external_id'],
                    'searched' => array_filter(['gtin' => $data['gtin'] ?? null, 'registration_number' => $data['registration_number'] ?? null]),
                    'suggestions' => $this->suggestions($data),
                ]);
        }
        if (count(array_unique($candidates)) > 1) {
            throw new BusinessRuleViolation('product_match_conflict',
                'The identifiers in this record point to different products. Correct the record in the source system or the mapping, then resend.', 409,
                ['matches' => $candidates]);
        }

        return [$this->approvedProduct(reset($candidates)), (string) array_key_first($candidates)];
    }

    /**
     * Product of a batch, stock or line record: by the source product key (which a product
     * record or a manual mapping must have registered first), else by GTIN.
     */
    public function productForReference(IntegrationConnector $connector, array $data): Product
    {
        $external = $data['product_external_id'] ?? null;
        if ($external !== null && $external !== '') {
            $ref = $this->reference($connector, 'product', $external);
            if ($ref === null) {
                throw new BusinessRuleViolation('product_reference_unknown',
                    "Product '{$external}' is not known for this connector yet. Send its product record first, or map it (POST /integration/messages/{id}/map with product_id), then retry.", 422,
                    ['product_external_id' => $external]);
            }
            if (! empty($data['gtin']) && ($byGtin = $this->byGtin($data['gtin'])) !== null && $byGtin !== $ref->internal_id) {
                throw new BusinessRuleViolation('product_match_conflict', 'The product key and the GTIN in this record point to different products.', 409,
                    ['reference' => $ref->internal_id, 'gtin' => $byGtin]);
            }

            return $this->approvedProduct($ref->internal_id);
        }

        $productId = $this->byGtin($data['gtin']);
        if ($productId === null) {
            throw new BusinessRuleViolation('product_unmatched', "No product in the Product Master has GTIN {$data['gtin']}.", 422, ['gtin' => $data['gtin']]);
        }

        return $this->approvedProduct($productId);
    }

    public function warehouse(IntegrationConnector $connector, string $code): Warehouse
    {
        $ref = $this->reference($connector, 'warehouse', $code);
        $warehouse = $ref !== null
            ? Warehouse::query()->find($ref->internal_id)
            : Warehouse::query()->where('code', $code)->first();

        if ($warehouse === null) {
            throw new BusinessRuleViolation('warehouse_unmatched',
                "Location '{$code}' does not match a warehouse. Map it to one of your warehouses (POST /integration/messages/{id}/map with warehouse_id), then retry.", 422,
                ['warehouse_code' => $code, 'warehouses' => Warehouse::query()->orderBy('code')->pluck('code')->all()]);
        }

        return $warehouse;
    }

    public function reference(IntegrationConnector $connector, string $entity, string $externalId): ?ExternalReference
    {
        return ExternalReference::query()->where('connector_id', $connector->id)->where('entity_type', $entity)->where('external_id', $externalId)->first();
    }

    /** Records (or re-points) an external id. Matched references never overwrite manual ones. */
    public function remember(IntegrationConnector $connector, string $entity, string $externalId, string $internalType, string $internalId, string $source = 'matched', ?int $userId = null): ExternalReference
    {
        $ref = $this->reference($connector, $entity, $externalId);
        if ($ref === null) {
            return ExternalReference::query()->create(['connector_id' => $connector->id, 'entity_type' => $entity, 'external_id' => $externalId,
                'internal_type' => $internalType, 'internal_id' => $internalId, 'source' => $source, 'created_by' => $userId]);
        }
        if ($source === 'manual' || ($ref->source !== 'manual' && $ref->internal_id !== $internalId)) {
            $ref->forceFill(['internal_id' => $internalId, 'source' => $source, 'created_by' => $userId ?? $ref->created_by])->save();
        }

        return $ref;
    }

    /** GTIN-13 "5012345678900" and GTIN-14 "05012345678900" are the same number. */
    public function byGtin(string $gtin): ?string
    {
        if (! Gtin::isValid($gtin)) {
            throw new BusinessRuleViolation('gtin_invalid', "GTIN {$gtin} has an invalid check digit.", 422, ['gtin' => $gtin]);
        }
        $core = ltrim($gtin, '0');
        $variants = array_values(array_unique(array_filter(array_map(
            fn ($length) => strlen($core) <= $length ? str_pad($core, $length, '0', STR_PAD_LEFT) : null, [8, 12, 13, 14]))));

        $ids = ProductIdentifier::query()->where('type', 'gtin')->whereIn('value', $variants)->distinct()->pluck('product_id');

        return $ids->count() === 1 ? $ids->first() : ($ids->isEmpty() ? null : throw new BusinessRuleViolation('product_match_ambiguous',
            "GTIN {$gtin} is recorded on more than one product.", 409, ['product_ids' => $ids->all()]));
    }

    private function byRegistration(string $number, ?string $authority): ?string
    {
        $ids = RegulatoryIdentifier::query()
            ->where('subject_type', 'product')
            ->where('value', strtoupper(trim($number)))
            ->when($authority, fn ($q) => $q->whereHas('authority', fn ($a) => $a->where('code', strtoupper($authority))))
            ->distinct()->pluck('subject_id');

        return $ids->count() === 1 ? $ids->first() : ($ids->isEmpty() ? null : throw new BusinessRuleViolation('product_match_ambiguous',
            "Registration number {$number} is recorded on more than one product.", 409, ['product_ids' => $ids->all()]));
    }

    private function approvedProduct(string $id): Product
    {
        $product = Product::query()->findOrFail($id);
        if ($product->status === ProductStatus::UnderReview) {
            throw new BusinessRuleViolation('product_not_approved', 'The matched product is still under catalogue review. Retry once it is approved.', 422, ['product_id' => $id]);
        }

        return $product;
    }

    /** Up to five Product Master entries that look like the record, to help a person map it. */
    private function suggestions(array $data): array
    {
        $term = $data['brand_name'] ?? $data['generic_name'] ?? $data['name'] ?? null;
        if ($term === null) {
            return [];
        }
        $term = trim(explode(' ', trim($term))[0]);

        return strlen($term) < 3 ? [] : Product::query()->search($term)->where('status', '!=', ProductStatus::UnderReview->value)
            ->limit(5)->get(['id', 'display_name', 'platform_sku'])->map(fn ($p) => ['product_id' => $p->id, 'display_name' => $p->display_name, 'platform_sku' => $p->platform_sku])->all();
    }
}

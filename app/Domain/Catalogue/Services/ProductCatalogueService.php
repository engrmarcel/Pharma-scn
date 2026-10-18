<?php

namespace App\Domain\Catalogue\Services;

use App\Domain\Catalogue\Enums\ProductStatus;
use App\Domain\Catalogue\Events\ProductCreated;
use App\Domain\Catalogue\Events\ProductUpdated;
use App\Domain\Catalogue\Models\Brand;
use App\Domain\Catalogue\Models\Ingredient;
use App\Domain\Catalogue\Models\Product;
use App\Domain\Catalogue\Support\Gtin;
use App\Domain\Catalogue\Support\PackageHierarchy;
use App\Domain\Platform\Models\DosageForm;
use App\Domain\Platform\Services\AuditLogger;
use App\Models\User;
use App\Support\Exceptions\BusinessRuleViolation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of canonical product records. Payloads are validated with
 * ProductRules before they reach this service; this layer enforces the
 * cross-field and cross-record rules.
 */
class ProductCatalogueService
{
    private const SCALAR_FIELDS = [
        'generic_name', 'display_name', 'strength_text', 'dosage_form_code', 'route_code', 'manufacturer_id',
        'marketing_authorization_holder', 'country_of_origin', 'category_id', 'description', 'storage_conditions',
        'temperature_min_c', 'temperature_max_c', 'requires_cold_chain', 'status',
    ];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly RegulatoryIdentifierService $regulatory,
    ) {}

    public function create(array $data, User $actor, ?string $submittedByOrganizationId = null): Product
    {
        return DB::transaction(function () use ($data, $actor, $submittedByOrganizationId) {
            $attributes = $this->scalarAttributes($data);
            $this->assertTemperatureRange($attributes);

            $product = new Product($attributes);
            $product->status ??= ProductStatus::UnderReview;
            $product->brand_id = $this->resolveBrandId($data, $attributes['manufacturer_id'] ?? null);
            $product->display_name = $attributes['display_name'] ?? $this->composeDisplayName($data);
            $product->submitted_by_organization_id = $submittedByOrganizationId;
            $product->approved_by = $actor->id;
            $product->approved_at = now();
            $product->id = $product->newUniqueId();
            $product->platform_sku = 'PRD-'.strtoupper(substr($product->id, -10));
            $product->save();

            $this->syncChildren($product, $data, $actor, $submittedByOrganizationId);

            $this->audit->record('product.created', $product, new: $product->fresh()->toArray() + ['payload' => $data], organizationId: false);
            event(new ProductCreated($product->id, $product->version ?? 1));

            return $product->fresh();
        });
    }

    /**
     * Optimistic concurrency: the caller states the version it edited.
     */
    public function update(Product $product, array $data, int $expectedVersion, User $actor, ?string $submittedByOrganizationId = null): Product
    {
        return DB::transaction(function () use ($product, $data, $expectedVersion, $actor, $submittedByOrganizationId) {
            $locked = Product::query()->lockForUpdate()->findOrFail($product->getKey());

            if ($locked->version !== $expectedVersion) {
                throw new BusinessRuleViolation('product_version_conflict', 'The product was changed by someone else. Reload it and reapply your change.', 409, [
                    'expected_version' => $expectedVersion,
                    'current_version' => $locked->version,
                ]);
            }

            $original = $locked->getAttributes();
            $locked->fill($this->scalarAttributes($data));
            $this->assertTemperatureRange($locked->getAttributes());

            if (array_key_exists('brand_name', $data)) {
                $locked->brand_id = $this->resolveBrandId($data, $locked->manufacturer_id);
            }
            $locked->version = $locked->version + 1;
            $locked->save();

            $this->syncChildren($locked, $data, $actor, $submittedByOrganizationId);

            [$old, $new] = $this->audit->diff($locked, $original);
            $children = array_intersect_key($data, array_flip(['ingredients', 'package_levels', 'identifiers', 'classifications', 'regulatory_identifiers']));
            $this->audit->record('product.updated', $locked, $old, $new + $children, organizationId: false);
            event(new ProductUpdated($locked->id, $locked->version, array_keys($new + $children)));

            return $locked->fresh();
        });
    }

    private function scalarAttributes(array $data): array
    {
        $attributes = Arr::only($data, self::SCALAR_FIELDS);
        if (isset($attributes['country_of_origin'])) {
            $attributes['country_of_origin'] = strtoupper($attributes['country_of_origin']);
        }

        return $attributes;
    }

    private function assertTemperatureRange(array $attributes): void
    {
        $min = $attributes['temperature_min_c'] ?? null;
        $max = $attributes['temperature_max_c'] ?? null;
        if ($min !== null && $max !== null && (float) $min > (float) $max) {
            throw new BusinessRuleViolation('temperature_range_invalid', 'Minimum storage temperature cannot exceed the maximum.');
        }
    }

    private function resolveBrandId(array $data, ?string $manufacturerId): ?string
    {
        $name = trim((string) ($data['brand_name'] ?? ''));

        return $name === '' ? null : Brand::query()->firstOrCreate(['manufacturer_id' => $manufacturerId, 'name' => $name])->id;
    }

    private function composeDisplayName(array $data): string
    {
        $form = DosageForm::query()->find($data['dosage_form_code'])?->name;

        return trim(implode(' ', array_filter([$data['brand_name'] ?? $data['generic_name'], $data['strength_text'] ?? null, $form])));
    }

    private function syncChildren(Product $product, array $data, User $actor, ?string $organizationId): void
    {
        if (array_key_exists('ingredients', $data)) {
            $sync = [];
            foreach (array_values($data['ingredients']) as $i => $row) {
                $ingredient = Ingredient::query()->firstOrCreate(['name' => trim($row['name'])]);
                $sync[$ingredient->id] = [
                    'strength_value' => $row['strength_value'],
                    'strength_unit' => $row['strength_unit'],
                    'per_value' => $row['per_value'] ?? null,
                    'per_unit' => $row['per_unit'] ?? null,
                    'sequence' => $i + 1,
                ];
            }
            $product->ingredients()->sync($sync);
        }

        if (array_key_exists('package_levels', $data)) {
            $this->replacePackageLevels($product, $data['package_levels']);
        }

        if (array_key_exists('identifiers', $data)) {
            $this->replaceIdentifiers($product, $data['identifiers']);
        }

        if (array_key_exists('classifications', $data)) {
            $product->classifications()->delete();
            foreach ($data['classifications'] as $row) {
                $product->classifications()->create(Arr::only($row, ['country_code', 'classification', 'effective_from', 'source']));
            }
        }

        // Additive only: regulatory identifiers carry verification history and are never dropped by an edit.
        foreach ($data['regulatory_identifiers'] ?? [] as $row) {
            $this->regulatory->attach($product, $row, $actor, $organizationId);
        }
    }

    private function replacePackageLevels(Product $product, array $levels): void
    {
        $built = PackageHierarchy::build($levels);

        // Identifiers may point at levels; they are re-linked by code below.
        $product->identifiers()->update(['package_level_id' => null]);
        $product->packageLevels()->delete();

        foreach ($built as $level) {
            $product->packageLevels()->create([
                'level' => $level['level'],
                'code' => $level['code'],
                'name' => $level['name'],
                'unit_code' => $level['unit_code'],
                'quantity_of_child' => $level['quantity_of_child'],
                'base_unit_quantity' => $level['base_unit_quantity'],
                'is_sellable' => $level['is_sellable'] ?? true,
                'is_orderable' => $level['is_orderable'] ?? true,
            ]);
        }
    }

    private function replaceIdentifiers(Product $product, array $identifiers): void
    {
        $levels = $product->packageLevels()->pluck('id', 'code');
        $product->identifiers()->delete();

        foreach ($identifiers as $row) {
            $value = trim($row['value']);
            if ($row['type'] === 'gtin' && ! Gtin::isValid($value)) {
                throw new BusinessRuleViolation('gtin_invalid', "GTIN {$value} has an invalid length or check digit.");
            }

            $levelCode = $row['package_level_code'] ?? null;
            if ($levelCode !== null && ! $levels->has($levelCode)) {
                throw new BusinessRuleViolation('package_level_unknown', "Identifier references unknown package level '{$levelCode}'.");
            }

            try {
                $product->identifiers()->create([
                    'type' => $row['type'],
                    'value' => $value,
                    'package_level_id' => $levelCode ? $levels[$levelCode] : null,
                    'is_primary' => $row['is_primary'] ?? false,
                ]);
            } catch (QueryException $e) {
                if (str_contains($e->getMessage(), 'Duplicate')) {
                    throw new BusinessRuleViolation('product_identifier_duplicate', "{$row['type']} {$value} is already assigned to another product.", 409);
                }
                throw $e;
            }
        }
    }
}

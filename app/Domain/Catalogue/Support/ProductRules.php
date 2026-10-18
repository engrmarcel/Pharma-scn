<?php

namespace App\Domain\Catalogue\Support;

use App\Domain\Catalogue\Enums\ProductStatus;
use Illuminate\Validation\Rule;

/**
 * One set of validation rules for a product payload, used by direct
 * catalogue edits, change-request submission and change-request approval.
 */
final class ProductRules
{
    public static function rules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'generic_name' => [$required, 'string', 'max:255'],
            'brand_name' => ['nullable', 'string', 'max:255'],
            'display_name' => ['nullable', 'string', 'max:255'],
            'strength_text' => ['nullable', 'string', 'max:100'],
            'dosage_form_code' => [$required, 'string', 'exists:dosage_forms,code'],
            'route_code' => ['nullable', 'string', 'exists:administration_routes,code'],
            'manufacturer_id' => ['nullable', 'ulid', 'exists:manufacturers,id'],
            'marketing_authorization_holder' => ['nullable', 'string', 'max:255'],
            'country_of_origin' => ['nullable', 'string', 'size:2', 'alpha'],
            'category_id' => ['nullable', 'ulid', 'exists:product_categories,id'],
            'description' => ['nullable', 'string', 'max:5000'],
            'storage_conditions' => ['nullable', 'string', 'max:2000'],
            'temperature_min_c' => ['nullable', 'numeric', 'between:-100,100'],
            'temperature_max_c' => ['nullable', 'numeric', 'between:-100,100'], // min <= max checked in ProductCatalogueService
            'requires_cold_chain' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::enum(ProductStatus::class)],

            'ingredients' => [$partial ? 'sometimes' : 'required', 'array', 'min:1', 'max:20'],
            'ingredients.*.name' => ['required', 'string', 'max:255', 'distinct:ignore_case'],
            'ingredients.*.strength_value' => ['required', 'numeric', 'gt:0'],
            'ingredients.*.strength_unit' => ['required', 'string', 'exists:units_of_measure,code'],
            'ingredients.*.per_value' => ['nullable', 'numeric', 'gt:0', 'required_with:ingredients.*.per_unit'],
            'ingredients.*.per_unit' => ['nullable', 'string', 'exists:units_of_measure,code', 'required_with:ingredients.*.per_value'],

            'package_levels' => [$partial ? 'sometimes' : 'required', 'array', 'min:1', 'max:8'],
            'package_levels.*.level' => ['required', 'integer', 'between:0,7', 'distinct'],
            'package_levels.*.code' => ['required', 'string', 'max:32', 'alpha_dash', 'distinct'],
            'package_levels.*.name' => ['required', 'string', 'max:255'],
            'package_levels.*.unit_code' => ['required', 'string', 'exists:units_of_measure,code'],
            'package_levels.*.quantity_of_child' => ['nullable', 'numeric', 'gt:0'],
            'package_levels.*.is_sellable' => ['sometimes', 'boolean'],
            'package_levels.*.is_orderable' => ['sometimes', 'boolean'],

            'identifiers' => ['sometimes', 'array', 'max:20'],
            'identifiers.*.type' => ['required', Rule::in(['gtin', 'manufacturer_code', 'other'])],
            'identifiers.*.value' => ['required', 'string', 'max:64'],
            'identifiers.*.package_level_code' => ['nullable', 'string', 'max:32'],
            'identifiers.*.is_primary' => ['sometimes', 'boolean'],

            'classifications' => ['sometimes', 'array', 'max:20'],
            'classifications.*.country_code' => ['required', 'string', 'exists:countries,code'],
            'classifications.*.classification' => ['required', Rule::in(['otc', 'pharmacy_only', 'prescription_only', 'controlled'])],
            'classifications.*.effective_from' => ['nullable', 'date'],
            'classifications.*.source' => ['nullable', 'string', 'max:255'],

            'regulatory_identifiers' => ['sometimes', 'array', 'max:20'],
            ...RegulatoryIdentifierRules::rules('regulatory_identifiers.*.'),
        ];
    }
}

<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'platform_sku' => $this->platform_sku,
            'display_name' => $this->display_name,
            'generic_name' => $this->generic_name,
            'brand_name' => $this->brand?->name,
            'strength_text' => $this->strength_text,
            'dosage_form_code' => $this->dosage_form_code,
            'route_code' => $this->route_code,
            'manufacturer' => $this->manufacturer ? ['id' => $this->manufacturer->id, 'name' => $this->manufacturer->name, 'country_code' => $this->manufacturer->country_code] : null,
            'marketing_authorization_holder' => $this->marketing_authorization_holder,
            'country_of_origin' => $this->country_of_origin,
            'category_id' => $this->category_id,
            'description' => $this->description,
            'storage' => [
                'conditions' => $this->storage_conditions,
                'temperature_min_c' => $this->temperature_min_c,
                'temperature_max_c' => $this->temperature_max_c,
                'requires_cold_chain' => $this->requires_cold_chain,
            ],
            'status' => $this->status->value,
            'version' => $this->version,
            'ingredients' => $this->whenLoaded('ingredients', fn () => $this->ingredients->map(fn ($i) => [
                'name' => $i->name,
                'strength_value' => $i->pivot->strength_value,
                'strength_unit' => $i->pivot->strength_unit,
                'per_value' => $i->pivot->per_value,
                'per_unit' => $i->pivot->per_unit,
            ])->values()),
            'package_levels' => $this->whenLoaded('packageLevels', fn () => $this->packageLevels->map(fn ($l) => [
                'level' => $l->level,
                'code' => $l->code,
                'name' => $l->name,
                'unit_code' => $l->unit_code,
                'quantity_of_child' => $l->quantity_of_child,
                'base_unit_quantity' => $l->base_unit_quantity,
                'is_sellable' => $l->is_sellable,
                'is_orderable' => $l->is_orderable,
            ])->values()),
            'identifiers' => $this->whenLoaded('identifiers', fn () => $this->identifiers->map(fn ($i) => $i->only(['type', 'value', 'is_primary', 'package_level_id']))->values()),
            'classifications' => $this->whenLoaded('classifications', fn () => $this->classifications->map(fn ($c) => [
                'country_code' => $c->country_code,
                'classification' => $c->classification,
                'effective_from' => $c->effective_from?->toDateString(),
            ])->values()),
            'regulatory_identifiers' => RegulatoryIdentifierResource::collection($this->whenLoaded('regulatoryIdentifiers')),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

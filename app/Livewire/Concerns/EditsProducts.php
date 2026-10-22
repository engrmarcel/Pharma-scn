<?php

namespace App\Livewire\Concerns;

/**
 * The product master form (ingredients, pack levels, identifiers), used to propose
 * catalogue changes and by platform catalogue staff. The API validates the payload.
 */
trait EditsProducts
{
    /** Product payload being edited, or null when the form is closed. */
    public ?array $product = null;

    /** For corrections: [product_id, version]. */
    public ?array $productBase = null;

    protected function blankProduct(): array
    {
        return [
            'generic_name' => '', 'brand_name' => '', 'strength_text' => '', 'dosage_form_code' => '', 'route_code' => '',
            'marketing_authorization_holder' => '', 'country_of_origin' => '', 'description' => '', 'storage_conditions' => '',
            'temperature_min_c' => '', 'temperature_max_c' => '', 'requires_cold_chain' => false,
            'ingredients' => [['name' => '', 'strength_value' => '', 'strength_unit' => 'mg']],
            'package_levels' => [['level' => 0, 'code' => '', 'name' => '', 'unit_code' => '', 'quantity_of_child' => '']],
            'identifiers' => [],
        ];
    }

    /** The form from an API product (for corrections and edits). */
    protected function productFromApi(array $p): array
    {
        return [
            'generic_name' => $p['generic_name'], 'brand_name' => $p['brand_name'] ?? '', 'strength_text' => $p['strength_text'] ?? '',
            'dosage_form_code' => $p['dosage_form_code'], 'route_code' => $p['route_code'] ?? '',
            'marketing_authorization_holder' => $p['marketing_authorization_holder'] ?? '', 'country_of_origin' => $p['country_of_origin'] ?? '',
            'description' => $p['description'] ?? '', 'storage_conditions' => $p['storage']['conditions'] ?? '',
            'temperature_min_c' => $p['storage']['temperature_min_c'] ?? '', 'temperature_max_c' => $p['storage']['temperature_max_c'] ?? '',
            'requires_cold_chain' => (bool) ($p['storage']['requires_cold_chain'] ?? false),
            'ingredients' => collect($p['ingredients'] ?? [])->map(fn ($i) => ['name' => $i['name'], 'strength_value' => (string) $i['strength_value'], 'strength_unit' => $i['strength_unit']])->all(),
            'package_levels' => collect($p['package_levels'] ?? [])->map(fn ($l) => ['level' => $l['level'], 'code' => $l['code'], 'name' => $l['name'], 'unit_code' => $l['unit_code'],
                'quantity_of_child' => $l['quantity_of_child'] === null ? '' : (string) $l['quantity_of_child']])->all(),
            'identifiers' => collect($p['identifiers'] ?? [])->map(fn ($i) => ['type' => $i['type'], 'value' => $i['value']])->all(),
        ];
    }

    /** The payload for the API: blanks removed, numbers left as typed. */
    protected function productPayload(): array
    {
        $clean = fn (array $row) => array_filter($row, fn ($v) => $v !== '' && $v !== null);
        $p = $this->product;
        $payload = $clean(collect($p)->except(['ingredients', 'package_levels', 'identifiers'])->all());
        $payload['requires_cold_chain'] = (bool) $p['requires_cold_chain'];
        $payload['ingredients'] = array_values(array_map($clean, $p['ingredients']));
        $payload['package_levels'] = array_values(array_map(fn ($l) => $clean($l) + ['level' => (int) $l['level']], $p['package_levels']));
        if ($p['identifiers'] !== []) {
            $payload['identifiers'] = array_values(array_map($clean, $p['identifiers']));
        }

        return $payload;
    }

    public function addProductRow(string $list): void
    {
        $row = match ($list) {
            'ingredients' => ['name' => '', 'strength_value' => '', 'strength_unit' => 'mg'],
            'package_levels' => ['level' => count($this->product['package_levels']), 'code' => '', 'name' => '', 'unit_code' => '', 'quantity_of_child' => ''],
            'identifiers' => ['type' => 'gtin', 'value' => ''],
        };
        $this->product[$list][] = $row;
    }

    public function removeProductRow(string $list, int $index): void
    {
        unset($this->product[$list][$index]);
        $this->product[$list] = array_values($this->product[$list]);
    }

    protected function catalogueReference(): array
    {
        return cache()->remember('web.catalogue-reference', 300, fn () => $this->api()->get('reference/catalogue')['data']);
    }
}

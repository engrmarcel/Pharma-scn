<?php

namespace App\Livewire\Concerns;

use App\Domain\Catalogue\Models\Product;

/**
 * Product lines on a document form held in `$doc['lines']` (requisitions, orders,
 * quotations). Each line: product_id, name, levels, package_level_code, quantity,
 * unit_price, tax_rate. Prices are only suggestions: the API prices and totals.
 */
trait EditsDocumentLines
{
    use PicksProducts;

    protected function blankLine(): array
    {
        return ['product_id' => '', 'name' => null, 'levels' => [], 'package_level_code' => '', 'quantity' => '1', 'unit_price' => '', 'tax_rate' => '', 'discount_percent' => ''];
    }

    public function addLine(): void
    {
        $this->doc['lines'][] = $this->blankLine();
    }

    public function removeLine(int $i): void
    {
        unset($this->doc['lines'][$i]);
        $this->doc['lines'] = array_values($this->doc['lines']);
    }

    protected function productPicked(string $target, array $product): void
    {
        $i = (int) str_replace('line.', '', $target);
        $price = $this->linePriceField() === 'cost' ? $product['cost'] : $product['price'];
        $this->doc['lines'][$i] = array_merge($this->doc['lines'][$i], [
            'product_id' => $product['product_id'], 'name' => $product['name'], 'levels' => $product['levels'],
            'package_level_code' => array_key_first($product['levels']) ?? '',
            'unit_price' => $this->doc['lines'][$i]['unit_price'] !== '' ? $this->doc['lines'][$i]['unit_price'] : (string) ($price ?? ''),
        ]);
    }

    /** "cost" for buying documents, "price" for selling documents. */
    protected function linePriceField(): string
    {
        return 'price';
    }

    /** Lines as the API expects them; blank optional fields left out. */
    protected function linesPayload(array $keep = ['product_id', 'package_level_code', 'quantity', 'unit_price', 'tax_rate']): array
    {
        return array_values(array_map(fn ($l) => array_filter(array_intersect_key($l, array_flip($keep)), fn ($v) => $v !== '' && $v !== null), $this->doc['lines'] ?? []));
    }

    /** Lines from an API document, ready for editing. */
    protected function linesFromApi(array $lines): array
    {
        $names = Product::query()->whereIn('id', array_column($lines, 'product_id'))->pluck('display_name', 'id');

        return array_map(fn ($l) => [
            'product_id' => $l['product_id'], 'name' => $names[$l['product_id']] ?? '', 'levels' => $this->packageLevels($l['product_id']),
            'package_level_code' => $l['package_level_code'] ?? '', 'quantity' => (string) (float) $l['quantity'],
            'unit_price' => isset($l['unit_price']) ? (string) (float) $l['unit_price'] : '', 'tax_rate' => isset($l['tax_rate']) ? (string) (float) $l['tax_rate'] : '',
            'discount_percent' => isset($l['discount_percent']) && (float) $l['discount_percent'] ? (string) (float) $l['discount_percent'] : '',
        ], $lines);
    }
}

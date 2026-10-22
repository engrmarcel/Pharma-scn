<?php

namespace App\Livewire\Concerns;

use App\Support\Web\ApiError;

/**
 * A product search shared by every form that has product lines.
 *
 * One search is open at a time: `$pickerTarget` says which line (or field) it fills.
 * Results come from the organization's own catalogue (SKU and default prices), falling
 * back to the shared product master. Picking calls `productPicked($target, $product)`.
 */
trait PicksProducts
{
    public string $productSearch = '';

    public ?string $pickerTarget = null;

    /** Share the open search's results with the view (and partials included from it). */
    public function renderingPicksProducts($view): void
    {
        $view->with('productOptions', $this->pickerTarget !== null ? $this->productOptions() : []);
    }

    public function openPicker(string $target): void
    {
        $this->pickerTarget = $target;
        $this->productSearch = '';
    }

    public function closePicker(): void
    {
        $this->pickerTarget = null;
        $this->productSearch = '';
    }

    /** @return list<array{product_id: string, name: string, sku: ?string, price: ?string, cost: ?string, currency: ?string}> */
    public function productOptions(): array
    {
        $q = trim($this->productSearch);
        if ($this->pickerTarget === null || mb_strlen($q) < 2) {
            return [];
        }

        try {
            $own = $this->api()->get('catalogue/items', ['q' => $q])['data'] ?? [];
        } catch (ApiError) {
            $own = [];
        }
        $options = collect($own)->take(10)->map(fn ($i) => [
            'product_id' => $i['product']['id'], 'name' => $i['local_name'] ?: $i['product']['display_name'], 'sku' => $i['sku'],
            'price' => $i['default_selling_price'], 'cost' => $i['default_purchase_cost'], 'currency' => $i['currency_code'],
        ]);

        if ($options->count() < 5) {
            $master = $this->api()->get('products', ['q' => $q, 'per_page' => 10])['data'] ?? [];
            foreach ($master as $p) {
                if (! $options->contains('product_id', $p['id'])) {
                    $options->push(['product_id' => $p['id'], 'name' => $p['display_name'], 'sku' => $p['platform_sku'] ?? null, 'price' => null, 'cost' => null, 'currency' => null]);
                }
            }
        }

        return $options->take(10)->values()->all();
    }

    public function pick(string $productId): void
    {
        $choice = collect($this->productOptions())->firstWhere('product_id', $productId);
        if ($choice === null) {
            $p = $this->api()->get("products/{$productId}")['data'];
            $choice = ['product_id' => $p['id'], 'name' => $p['display_name'], 'sku' => $p['platform_sku'] ?? null, 'price' => null, 'cost' => null, 'currency' => null];
        }
        $choice['levels'] = $this->packageLevels($productId);
        $target = $this->pickerTarget;
        $this->closePicker();
        $this->productPicked((string) $target, $choice);
    }

    /** Pack levels of a product (code => name), smallest first. */
    protected function packageLevels(string $productId): array
    {
        try {
            $levels = $this->api()->get("products/{$productId}")['data']['package_levels'] ?? [];
        } catch (ApiError) {
            return [];
        }

        return collect($levels)->sortBy('level')->mapWithKeys(fn ($l) => [$l['code'] => $l['name']])->all();
    }

    /** Fill the target with the chosen product. */
    abstract protected function productPicked(string $target, array $product): void;
}

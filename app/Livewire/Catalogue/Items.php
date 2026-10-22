<?php

namespace App\Livewire\Catalogue;

use App\Livewire\Concerns\PicksProducts;
use App\Livewire\WebPage;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

/** The organization's own catalogue: which products it trades, its SKU, prices and reorder settings. */
#[Title('Our catalogue')]
class Items extends WebPage
{
    use PicksProducts;

    public const ROUTE = 'catalogue.items';

    #[Url]
    public string $search = '';

    public ?array $item = null;

    protected array $filters = ['search'];

    public function create(): void
    {
        $this->resetErrorBag();
        $this->item = ['id' => null, 'product_id' => '', 'product_name' => null, 'sku' => '', 'local_name' => '', 'default_purchase_cost' => '', 'default_selling_price' => '',
            'tax_category_code' => '', 'preferred_supplier_id' => '', 'reorder_level' => '', 'reorder_quantity' => '', 'status' => 'active'];
    }

    public function edit(array $row): void
    {
        $this->resetErrorBag();
        $this->item = ['id' => $row['id'], 'product_id' => $row['product']['id'] ?? '', 'product_name' => $row['product']['display_name'] ?? null] + array_map(fn ($v) => $v ?? '',
            array_intersect_key($row, array_flip(['sku', 'local_name', 'default_purchase_cost', 'default_selling_price', 'tax_category_code', 'preferred_supplier_id', 'reorder_level', 'reorder_quantity', 'status'])));
    }

    protected function productPicked(string $target, array $product): void
    {
        $this->item['product_id'] = $product['product_id'];
        $this->item['product_name'] = $product['name'];
        if ($this->item['sku'] === '' && $product['sku']) {
            $this->item['sku'] = $product['sku'];
        }
    }

    public function save(): void
    {
        $data = collect($this->item)->except(['id', 'product_name'])->map(fn ($v) => $v === '' ? null : $v)->all();
        $result = $this->item['id']
            ? $this->submit('item', "catalogue/items/{$this->item['id']}", collect($data)->except('product_id')->filter(fn ($v, $k) => $v !== null || in_array($k, ['local_name', 'preferred_supplier_id'], true))->all(), 'Catalogue item updated.', 'patch')
            : $this->submit('item', 'catalogue/items', array_filter($data, fn ($v) => $v !== null), 'Product added to your catalogue.');
        if ($result !== null) {
            $this->item = null;
        }
    }

    public function render()
    {
        $result = $this->api()->get('catalogue/items', ['q' => trim($this->search), 'page' => $this->page]);

        return view('livewire.catalogue.items', [
            'rows' => $result['data'] ?? [],
            'meta' => $result['meta'] ?? [],
            'suppliers' => collect($this->read('suppliers', ['status' => 'active'])['data'] ?? [])->pluck('name', 'id'),
            'canManage' => $this->can('tenant_products.manage'),
        ]);
    }
}

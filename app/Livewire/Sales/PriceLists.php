<?php

namespace App\Livewire\Sales;

use App\Livewire\Concerns\PicksProducts;
use App\Livewire\WebPage;
use Livewire\Attributes\Title;

/** Selling price lists: create a list, then maintain its prices per product and pack level. */
#[Title('Price lists')]
class PriceLists extends WebPage
{
    use PicksProducts;

    public const ROUTE = 'sales.price-lists';

    public ?array $list = null;

    /** Price list whose items are being edited: [id, items[]]. */
    public ?array $editing = null;

    public ?string $selectedId = null;

    public function create(): void
    {
        $this->resetErrorBag();
        $this->list = ['code' => '', 'name' => '', 'branch_id' => '', 'is_default' => false];
    }

    public function save(): void
    {
        $result = $this->submit('list', 'price-lists', array_filter($this->list, fn ($v) => $v !== '') + ['is_default' => (bool) $this->list['is_default']], 'Price list created.');
        if ($result !== null) {
            $this->list = null;
            $this->selectedId = $result['data']['id'];
        }
    }

    public function editItems(string $id): void
    {
        $list = collect($this->api()->get('price-lists')['data'])->firstWhere('id', $id);
        $names = $this->productNames(collect($list['items'])->pluck('product_id'));
        $this->resetErrorBag();
        $this->editing = ['id' => $id, 'items' => collect($list['items'])->map(fn ($i) => [
            'product_id' => $i['product_id'], 'name' => $names[$i['product_id']] ?? '', 'levels' => $this->packageLevels($i['product_id']),
            'package_level_code' => $i['package_level_code'], 'unit_price' => (string) (float) $i['unit_price'], 'minimum_quantity' => (string) (float) $i['minimum_quantity'],
            'valid_from' => $i['valid_from'] ? substr($i['valid_from'], 0, 10) : '', 'valid_to' => $i['valid_to'] ? substr($i['valid_to'], 0, 10) : '',
        ])->all()];
    }

    public function addItem(): void
    {
        $this->editing['items'][] = ['product_id' => '', 'name' => null, 'levels' => [], 'package_level_code' => '', 'unit_price' => '', 'minimum_quantity' => '1', 'valid_from' => '', 'valid_to' => ''];
    }

    public function removeItem(int $i): void
    {
        unset($this->editing['items'][$i]);
        $this->editing['items'] = array_values($this->editing['items']);
    }

    protected function productPicked(string $target, array $product): void
    {
        $i = (int) str_replace('item.', '', $target);
        $this->editing['items'][$i] = array_merge($this->editing['items'][$i], ['product_id' => $product['product_id'], 'name' => $product['name'], 'levels' => $product['levels'],
            'package_level_code' => array_key_first($product['levels']) ?? '', 'unit_price' => $this->editing['items'][$i]['unit_price'] ?: (string) ($product['price'] ?? '')]);
    }

    public function saveItems(): void
    {
        $items = collect($this->editing['items'])->map(fn ($i) => array_filter(collect($i)->only(['product_id', 'package_level_code', 'unit_price', 'minimum_quantity', 'valid_from', 'valid_to'])->all(), fn ($v) => $v !== ''))->values()->all();
        if ($this->submit('items', "price-lists/{$this->editing['id']}/items", ['items' => $items], 'Prices saved.', 'put') !== null) {
            $this->editing = null;
        }
    }

    public function render()
    {
        $lists = $this->api()->get('price-lists')['data'] ?? [];
        $this->selectedId ??= $lists[0]['id'] ?? null;
        $selected = collect($lists)->firstWhere('id', $this->selectedId);

        return view('livewire.sales.price-lists', [
            'lists' => $lists,
            'selected' => $selected,
            'names' => $this->productNames(collect($selected['items'] ?? [])->pluck('product_id')),
            'branches' => collect($this->read('branches')['data'] ?? [])->pluck('name', 'id'),
            'canManage' => $this->can('sales.price_lists.manage'),
        ]);
    }
}

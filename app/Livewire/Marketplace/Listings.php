<?php

namespace App\Livewire\Marketplace;

use App\Domain\Marketplace\Models\MarketplaceListing;
use App\Domain\Organizations\Enums\OrganizationType;
use App\Livewire\Concerns\PicksProducts;
use App\Livewire\WebPage;
use Livewire\Attributes\Title;

/** What the organization offers on the marketplace: price, minimum, availability (live stock or declared) and who may buy. */
#[Title('Marketplace listings')]
class Listings extends WebPage
{
    use PicksProducts;

    public const ROUTE = 'marketplace.listings';

    public string $status = '';

    public ?array $form = null;

    protected array $filters = ['status'];

    public function create(): void
    {
        $this->resetErrorBag();
        $this->form = ['id' => null, 'product_id' => '', 'product_name' => null, 'levels' => [], 'package_level_code' => '', 'unit_price' => '', 'minimum_order_quantity' => '1',
            'availability_mode' => 'live', 'declared_availability' => 'in_stock', 'warehouse_id' => '', 'lead_time_days' => '', 'allowed_buyer_types' => [], 'status' => 'active'];
    }

    public function edit(array $l, string $name): void
    {
        $this->resetErrorBag();
        $this->form = ['id' => $l['id'], 'product_id' => $l['product_id'], 'product_name' => $name, 'levels' => [], 'package_level_code' => $l['package_level_code'],
            'unit_price' => (string) (float) $l['unit_price'], 'minimum_order_quantity' => (string) (float) $l['minimum_order_quantity'], 'availability_mode' => $l['availability_mode'],
            'declared_availability' => $l['declared_availability'] ?? 'in_stock', 'warehouse_id' => $l['warehouse_id'] ?? '', 'lead_time_days' => (string) ($l['lead_time_days'] ?? ''),
            'allowed_buyer_types' => (array) ($l['allowed_buyer_types'] ?? []), 'status' => $l['status']];
    }

    protected function productPicked(string $target, array $product): void
    {
        $this->form = array_merge($this->form, ['product_id' => $product['product_id'], 'product_name' => $product['name'], 'levels' => $product['levels'],
            'package_level_code' => array_key_first($product['levels']) ?? '', 'unit_price' => $this->form['unit_price'] ?: (string) ($product['price'] ?? '')]);
    }

    public function save(): void
    {
        $f = $this->form;
        $data = collect($f)->only(['product_id', 'package_level_code', 'unit_price', 'minimum_order_quantity', 'availability_mode', 'declared_availability', 'warehouse_id', 'lead_time_days', 'status'])
            ->filter(fn ($v) => $v !== '')->all();
        if ($f['availability_mode'] !== 'declared') {
            unset($data['declared_availability']);
        }
        $data['allowed_buyer_types'] = array_values($f['allowed_buyer_types']) ?: null;
        $result = $f['id'] ? $this->submit('form', "marketplace/listings/{$f['id']}", collect($data)->except(['product_id', 'package_level_code'])->all(), 'Listing updated.', 'patch')
            : $this->submit('form', 'marketplace/listings', $data, 'Listing published.');
        if ($result !== null) {
            $this->form = null;
        }
    }

    public function render()
    {
        $result = $this->api()->get('marketplace/listings', ['status' => $this->status, 'page' => $this->page]);
        $rows = $result['data'] ?? [];

        return view('livewire.marketplace.listings', [
            'rows' => $rows, 'meta' => $result,
            'names' => $this->productNames(array_column($rows, 'product_id')),
            'warehouses' => $this->warehouseOptions(),
            'declared' => MarketplaceListing::DECLARED,
            'types' => OrganizationType::cases(),
        ]);
    }
}

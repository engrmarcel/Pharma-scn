<?php

namespace App\Livewire\Marketplace;

use App\Domain\Organizations\Enums\OrganizationType;
use App\Livewire\WebPage;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

/** Find verified suppliers' offers, request to trade, build a cart per supplier and place orders. */
#[Title('Marketplace')]
class Discover extends WebPage
{
    public const ROUTE = 'marketplace.discover';

    #[Url]
    public string $search = '';

    public string $supplierType = '';

    public bool $verifiedOnly = true;

    public bool $availableOnly = false;

    /** supplier_id => [name, lines => listing_id => [name, pack, price, currency, quantity, moq]]. */
    public array $cart = [];

    public ?array $checkout = null;

    public ?string $supplierId = null;

    public ?array $requesting = null;

    protected array $filters = ['search', 'supplierType', 'verifiedOnly', 'availableOnly'];

    public function add(array $offer): void
    {
        $sid = $offer['supplier']['id'];
        $this->cart[$sid]['name'] = $offer['supplier']['name'];
        $this->cart[$sid]['lines'][$offer['listing_id']] = ['name' => $offer['product']['display_name'], 'pack' => $offer['pack']['name'], 'price' => $offer['unit_price'],
            'currency' => $offer['currency_code'], 'quantity' => (string) max(1, (float) $offer['minimum_order_quantity']), 'moq' => $offer['minimum_order_quantity']];
        $this->notify($offer['product']['display_name'].' added to your order from '.$offer['supplier']['name'].'.');
    }

    public function removeLine(string $supplierId, string $listingId): void
    {
        unset($this->cart[$supplierId]['lines'][$listingId]);
        if (empty($this->cart[$supplierId]['lines'])) {
            unset($this->cart[$supplierId]);
        }
    }

    public function startCheckout(string $supplierId): void
    {
        $this->resetErrorBag();
        $this->checkout = ['supplier_id' => $supplierId, 'delivery_warehouse_id' => array_key_first($this->warehouseOptions()) ?? '', 'requested_date' => '', 'notes' => ''];
    }

    public function placeOrder(): void
    {
        $c = $this->checkout;
        $lines = collect($this->cart[$c['supplier_id']]['lines'] ?? [])->map(fn ($l, $id) => ['listing_id' => $id, 'quantity' => $l['quantity']])->values()->all();
        $order = $this->submit('checkout', 'marketplace/orders', array_filter(['supplier_organization_id' => $c['supplier_id'], 'delivery_warehouse_id' => $c['delivery_warehouse_id'],
            'requested_date' => $c['requested_date'], 'notes' => $c['notes']]) + ['lines' => $lines]);
        if ($order === null) {
            return;
        }
        $submitted = $this->attempt(fn ($api) => $api->post("marketplace/orders/{$order['data']['id']}/submit"));
        unset($this->cart[$c['supplier_id']]);
        $this->checkout = null;
        $this->notify($submitted !== null ? 'Order '.$order['data']['number'].' sent to the supplier.' : 'Order '.$order['data']['number'].' saved as a draft.');
    }

    public function startRequest(string $supplierId, string $name): void
    {
        $this->resetErrorBag();
        $this->requesting = ['supplier_organization_id' => $supplierId, 'name' => $name, 'message' => ''];
    }

    public function sendRequest(): void
    {
        $r = $this->requesting;
        if ($this->submit('requesting', 'marketplace/relationships', ['supplier_organization_id' => $r['supplier_organization_id'], 'message' => $r['message'] ?: null], 'Trading request sent.') !== null) {
            $this->requesting = null;
        }
    }

    public function render()
    {
        $result = $this->api()->get('marketplace/discover', ['q' => trim($this->search), 'supplier_type' => $this->supplierType,
            'verified_only' => $this->verifiedOnly ? 1 : null, 'available_only' => $this->availableOnly ? 1 : null, 'page' => $this->page]);

        return view('livewire.marketplace.discover', [
            'offers' => $result['data'] ?? [],
            'meta' => $result['meta'] ?? [],
            'supplier' => $this->supplierId ? ($this->read("marketplace/suppliers/{$this->supplierId}")['data'] ?? null) : null,
            'warehouses' => $this->warehouseOptions(),
            'types' => OrganizationType::cases(),
            'canOrder' => $this->can('marketplace.orders.place'),
            'canRelate' => $this->can('marketplace.relationships.manage'),
        ]);
    }
}

<?php

namespace App\Livewire\Concerns;

use App\Support\Tenancy\TenantContext;

/**
 * The buyer's marketplace cart, one per organization and person, kept in the session so it
 * follows them across the trading floor, the supplier storefronts and Discover. It holds what is
 * shown (names and list prices); the order itself sends only listing ids and quantities, and the
 * supplier's current price, minimum order and trading rules are applied by the server.
 *
 * Cart shape: supplier_id => [name, lines => listing_id => [name, pack, price, currency, quantity, moq]].
 */
trait UsesMarketplaceCart
{
    /** Checkout form for one supplier's cart. */
    public ?array $checkout = null;

    protected function cartKey(): string
    {
        return 'marketplace.cart.'.app(TenantContext::class)->id().'.'.auth()->id();
    }

    public function cart(): array
    {
        return session($this->cartKey(), []);
    }

    private function saveCart(array $cart): void
    {
        session([$this->cartKey() => $cart]);
    }

    /** Add an offer from Discover or a storefront (the discovery shape of a listing). */
    public function add(array $offer): void
    {
        if (! ($offer['restriction']['you_may_buy'] ?? true)) {
            $this->notify($offer['restriction']['reason'] ?? 'You may not buy this offer.', 'error');

            return;
        }
        $cart = $this->cart();
        $sid = $offer['supplier']['id'];
        $existing = $cart[$sid]['lines'][$offer['listing_id']]['quantity'] ?? null;
        $cart[$sid]['name'] = $offer['supplier']['name'];
        $cart[$sid]['lines'][$offer['listing_id']] = ['name' => $offer['product']['display_name'], 'pack' => $offer['pack']['name'], 'price' => $offer['unit_price'],
            'currency' => $offer['currency_code'], 'moq' => $offer['minimum_order_quantity'],
            'quantity' => $existing !== null ? bcadd((string) $existing, '1', 4) : (string) max(1, (float) $offer['minimum_order_quantity'])];
        $this->saveCart($cart);
        $this->notify($offer['product']['display_name'].' added to your order from '.$offer['supplier']['name'].'.');
    }

    public function setCartQuantity(string $supplierId, string $listingId, string $quantity): void
    {
        $cart = $this->cart();
        if (! isset($cart[$supplierId]['lines'][$listingId])) {
            return;
        }
        if (! is_numeric($quantity) || (float) $quantity <= 0) {
            $this->removeLine($supplierId, $listingId);

            return;
        }
        $cart[$supplierId]['lines'][$listingId]['quantity'] = $quantity;
        $this->saveCart($cart);
    }

    public function removeLine(string $supplierId, string $listingId): void
    {
        $cart = $this->cart();
        unset($cart[$supplierId]['lines'][$listingId]);
        if (empty($cart[$supplierId]['lines'])) {
            unset($cart[$supplierId]);
        }
        $this->saveCart($cart);
    }

    public function startCheckout(string $supplierId): void
    {
        $this->resetErrorBag();
        $this->checkout = ['supplier_id' => $supplierId, 'delivery_warehouse_id' => array_key_first($this->warehouseOptions()) ?? '', 'requested_date' => '', 'notes' => ''];
    }

    public function placeOrder(): void
    {
        $c = $this->checkout;
        $cart = $this->cart();
        $lines = collect($cart[$c['supplier_id']]['lines'] ?? [])->map(fn ($l, $id) => ['listing_id' => $id, 'quantity' => $l['quantity']])->values()->all();
        $order = $this->submit('checkout', 'marketplace/orders', array_filter(['supplier_organization_id' => $c['supplier_id'], 'delivery_warehouse_id' => $c['delivery_warehouse_id'],
            'requested_date' => $c['requested_date'], 'notes' => $c['notes']]) + ['lines' => $lines]);
        if ($order === null) {
            return;
        }
        $submitted = $this->attempt(fn ($api) => $api->post("marketplace/orders/{$order['data']['id']}/submit"));
        unset($cart[$c['supplier_id']]);
        $this->saveCart($cart);
        $this->checkout = null;
        $this->notify($submitted !== null ? 'Order '.$order['data']['number'].' sent to the supplier.' : 'Order '.$order['data']['number'].' saved as a draft.');
    }

    /** Data the cart partial needs. */
    protected function cartView(): array
    {
        return ['cart' => $this->cart(), 'warehouses' => $this->warehouseOptions(), 'canOrder' => $this->can('marketplace.orders.place')];
    }
}

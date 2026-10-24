<?php

namespace App\Livewire\Marketplace;

use App\Livewire\Concerns\RequestsTradingRelationships;
use App\Livewire\Concerns\UsesMarketplaceCart;
use App\Livewire\WebPage;
use App\Support\Tenancy\TenantContext;
use App\Support\Web\ApiError;
use Livewire\Attributes\Title;

/**
 * A supplier's storefront: their marketplace profile (who they are, where and how they deliver,
 * minimum order, contact) and every offer they list, with whether you may buy each, your trading
 * status with them, and the actions that follow (request to trade, add to your order, ask for a quote).
 * A supplier opening its own storefront sees it as buyers do, with its listings as a preview.
 */
#[Title('Supplier storefront')]
class Storefront extends WebPage
{
    use RequestsTradingRelationships, UsesMarketplaceCart;

    /** Same permissions as the supplier directory. */
    public const ROUTE = 'marketplace.suppliers';

    public string $supplierId = '';

    public string $search = '';

    protected array $filters = ['search'];

    public function mount(string $supplier): void
    {
        $this->supplierId = $supplier;
    }

    public function render()
    {
        $own = $this->supplierId === app(TenantContext::class)->id();
        try {
            $supplier = $this->api()->get("marketplace/suppliers/{$this->supplierId}")['data'];
        } catch (ApiError $e) {
            abort($e->status === 404 ? 404 : 403, $e->getMessage());
        }

        $offers = $own ? [] : ($this->api()->get('marketplace/discover', ['supplier' => $this->supplierId, 'q' => trim($this->search), 'page' => $this->page])['data'] ?? []);
        $ownListings = $own ? ($this->read('marketplace/listings', ['status' => 'active'], ['data' => []])['data'] ?? []) : [];

        return view('livewire.marketplace.storefront', [
            'supplier' => $supplier,
            'own' => $own,
            'offers' => $offers,
            'ownListings' => $ownListings,
            'names' => $own ? $this->productNames(collect($ownListings)->pluck('product_id')->all()) : [],
            'canRelate' => $this->can('marketplace.relationships.manage'),
            'canRfq' => $this->can('marketplace.rfq.manage'),
        ] + $this->cartView());
    }
}

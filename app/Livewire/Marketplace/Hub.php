<?php

namespace App\Livewire\Marketplace;

use App\Livewire\Concerns\UsesMarketplaceCart;
use App\Livewire\WebPage;
use Livewire\Attributes\Title;

/**
 * The trading floor: the marketplace's home. What needs the organization's attention as a buyer and as
 * a supplier (each item opens the screen where it is dealt with), the last 30 days in figures, recent
 * trading activity, a quick product search, featured suppliers and the buyer's open cart.
 */
#[Title('Trading floor')]
class Hub extends WebPage
{
    use UsesMarketplaceCart;

    public const ROUTE = 'marketplace.home';

    public string $quickSearch = '';

    public function searchOffers()
    {
        return $this->redirectRoute('marketplace.discover', array_filter(['search' => trim($this->quickSearch)]));
    }

    public function render()
    {
        $activity = $this->read('marketplace/activity', [], ['data' => null])['data'];
        $featured = $this->can('marketplace.browse') ? array_slice($this->read('marketplace/suppliers', ['verified_only' => 1], ['data' => []])['data'], 0, 4) : [];

        return view('livewire.marketplace.hub', [
            'a' => $activity,
            'featured' => $featured,
            'canBrowse' => $this->can('marketplace.browse'),
        ] + $this->cartView());
    }
}

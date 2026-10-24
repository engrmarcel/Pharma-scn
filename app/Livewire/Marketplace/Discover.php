<?php

namespace App\Livewire\Marketplace;

use App\Domain\Organizations\Enums\OrganizationType;
use App\Livewire\Concerns\RequestsTradingRelationships;
use App\Livewire\Concerns\UsesMarketplaceCart;
use App\Livewire\WebPage;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

/**
 * Find suppliers' offers: as cards, or compared by product with the price per smallest unit,
 * so packs of different sizes from different suppliers can be weighed against each other.
 * Offers go into the buyer's cart (one order per supplier); unknown suppliers are asked to trade first.
 */
#[Title('Discover offers')]
class Discover extends WebPage
{
    use RequestsTradingRelationships, UsesMarketplaceCart;

    public const ROUTE = 'marketplace.discover';

    #[Url]
    public string $search = '';

    #[Url(as: 'type')]
    public string $supplierType = '';

    public bool $verifiedOnly = true;

    public bool $availableOnly = false;

    /** offers (cards) or compare (grouped by product, cheapest per unit first). */
    #[Url]
    public string $view = 'offers';

    protected array $filters = ['search', 'supplierType', 'verifiedOnly', 'availableOnly'];

    public function render()
    {
        $result = $this->api()->get('marketplace/discover', ['q' => trim($this->search), 'supplier_type' => $this->supplierType,
            'verified_only' => $this->verifiedOnly ? 1 : null, 'available_only' => $this->availableOnly ? 1 : null, 'page' => $this->page]);
        $offers = $result['data'] ?? [];

        $compare = collect($offers)->groupBy(fn ($o) => $o['product']['id'])->map(function ($group) {
            $sorted = $group->sortBy(fn ($o) => (float) ($o['price_per_base_unit'] ?? $o['unit_price']))->values();
            $buyable = $sorted->first(fn ($o) => $o['restriction']['you_may_buy']);

            return ['product' => $sorted->first()['product'], 'offers' => $sorted->all(), 'best' => $buyable['listing_id'] ?? null];
        })->values()->all();

        return view('livewire.marketplace.discover', [
            'offers' => $offers,
            'compare' => $compare,
            'meta' => $result['meta'] ?? [],
            'types' => collect(OrganizationType::cases())->filter(fn ($t) => array_key_exists($t->value, config('marketplace.buyer_types_by_supplier_type')))->all(),
            'canRelate' => $this->can('marketplace.relationships.manage'),
        ] + $this->cartView());
    }
}

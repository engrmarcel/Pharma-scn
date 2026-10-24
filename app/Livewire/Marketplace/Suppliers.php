<?php

namespace App\Livewire\Marketplace;

use App\Domain\Organizations\Enums\OrganizationType;
use App\Livewire\Concerns\RequestsTradingRelationships;
use App\Livewire\WebPage;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

/** Directory of suppliers on the marketplace: who they are, where they deliver, and whether you can trade with them. */
#[Title('Suppliers')]
class Suppliers extends WebPage
{
    use RequestsTradingRelationships;

    public const ROUTE = 'marketplace.suppliers';

    #[Url]
    public string $search = '';

    #[Url(as: 'type')]
    public string $supplierType = '';

    public string $region = '';

    public bool $verifiedOnly = false;

    protected array $filters = ['search', 'supplierType', 'region', 'verifiedOnly'];

    public function render()
    {
        $result = $this->api()->get('marketplace/suppliers', array_filter(['q' => trim($this->search), 'type' => $this->supplierType, 'state' => trim($this->region),
            'verified_only' => $this->verifiedOnly ? 1 : null, 'page' => $this->page]));

        return view('livewire.marketplace.suppliers', [
            'suppliers' => $result['data'] ?? [],
            'meta' => $result['meta'] ?? [],
            'types' => collect(OrganizationType::cases())->filter(fn ($t) => array_key_exists($t->value, config('marketplace.buyer_types_by_supplier_type')))->all(),
            'canRelate' => $this->can('marketplace.relationships.manage'),
        ]);
    }
}

<?php

namespace App\Livewire\Marketplace;

use App\Domain\Organizations\Models\Organization;
use App\Livewire\WebPage;
use App\Support\Tenancy\TenantContext;
use Livewire\Attributes\Title;

/** Quotes sent and received: accept into an order, reject with a note, or withdraw your own. */
#[Title('Quotes')]
class Quotes extends WebPage
{
    public const ROUTE = 'marketplace.quotes';

    public string $status = '';

    public ?string $rejecting = null;

    public string $notes = '';

    protected array $filters = ['status'];

    public function accept(string $id): void
    {
        $result = $this->attempt(fn ($api) => $api->post("marketplace/quotes/{$id}/accept"), 'Quote accepted: an order was created.');
        if ($result !== null) {
            $this->redirectRoute('marketplace.orders', ['open' => $result['data']['id']], navigate: false);
        }
    }

    public function reject(): void
    {
        if ($this->submit('reject', "marketplace/quotes/{$this->rejecting}/reject", ['notes' => $this->notes], 'Quote rejected.') !== null) {
            $this->rejecting = null;
            $this->notes = '';
        }
    }

    public function withdraw(string $id): void
    {
        $this->attempt(fn ($api) => $api->post("marketplace/quotes/{$id}/withdraw"), 'Quote withdrawn.');
    }

    public function render()
    {
        $result = $this->api()->get('marketplace/quotes', ['status' => $this->status, 'page' => $this->page]);
        $rows = $result['data'] ?? [];
        $me = app(TenantContext::class)->id();
        $orgs = Organization::query()->whereIn('id', collect($rows)->flatMap(fn ($q) => [$q['buyer_organization_id'], $q['supplier_organization_id']])->unique())->pluck('name', 'id');

        return view('livewire.marketplace.quotes', [
            'rows' => $rows, 'meta' => $result, 'me' => $me, 'orgs' => $orgs,
            'names' => $this->productNames(collect($rows)->pluck('lines')->flatten(1)->pluck('product_id')),
            'can' => ['place' => $this->can('marketplace.orders.place'), 'manage' => $this->can('marketplace.rfq.manage'), 'respond' => $this->can('marketplace.quotes.respond')],
        ]);
    }
}

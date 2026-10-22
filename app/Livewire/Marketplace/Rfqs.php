<?php

namespace App\Livewire\Marketplace;

use App\Livewire\Concerns\EditsDocumentLines;
use App\Livewire\WebPage;
use App\Support\Tenancy\TenantContext;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

/**
 * Requests for quotation. Buyers invite suppliers and compare offers line by line;
 * suppliers answer with a quote or decline.
 */
#[Title('Requests for quotation')]
class Rfqs extends WebPage
{
    use EditsDocumentLines;

    public const ROUTE = 'marketplace.rfqs';

    #[Url]
    public string $as = '';

    #[Url(as: 'open')]
    public ?string $openId = null;

    public ?array $doc = null;

    /** Supplier's answer: valid_until, notes, lines rfq_line_id => [unit_price, quantity, lead_time_days]. */
    public ?array $quote = null;

    public string $reason = '';

    protected array $filters = ['as'];

    public function create(): void
    {
        $this->resetErrorBag();
        $this->doc = ['title' => '', 'response_deadline' => now()->addDays(5)->format('Y-m-d\TH:i'), 'delivery_warehouse_id' => array_key_first($this->warehouseOptions()) ?? '',
            'notes' => '', 'suppliers' => [], 'lines' => [$this->blankLine()]];
    }

    public function save(): void
    {
        $d = $this->doc;
        $result = $this->submit('doc', 'marketplace/rfqs', ['title' => $d['title'], 'response_deadline' => $d['response_deadline'], 'delivery_warehouse_id' => $d['delivery_warehouse_id'],
            'notes' => $d['notes'] ?: null, 'supplier_organization_ids' => array_keys(array_filter($d['suppliers'])),
            'lines' => $this->linesPayload(['product_id', 'package_level_code', 'quantity'])], 'RFQ created.');
        if ($result !== null) {
            $this->doc = null;
            $this->openId = $result['data']['id'];
        }
    }

    public function changeStatus(string $action): void
    {
        $this->submit('rfq', "marketplace/rfqs/{$this->openId}/{$action}", array_filter(['reason' => $this->reason]), ['open' => 'RFQ sent to the invited suppliers.', 'close' => 'RFQ closed.', 'cancel' => 'RFQ cancelled.'][$action]);
    }

    public function startQuote(array $rfq): void
    {
        $this->resetErrorBag();
        $this->quote = ['valid_until' => business_today()->addDays(14)->toDateString(), 'notes' => '',
            'lines' => collect($rfq['lines'])->mapWithKeys(fn ($l) => [$l['id'] => ['quantity' => (string) (float) $l['quantity'], 'unit_price' => '', 'lead_time_days' => '']])->all()];
    }

    public function sendQuote(): void
    {
        $lines = collect($this->quote['lines'])->filter(fn ($l) => $l['unit_price'] !== '')->map(fn ($l, $id) => array_filter(['rfq_line_id' => (int) $id] + $l, fn ($v) => $v !== ''))->values()->all();
        if ($this->submit('quote', "marketplace/rfqs/{$this->openId}/quotes", ['valid_until' => $this->quote['valid_until'], 'notes' => $this->quote['notes'] ?: null, 'lines' => $lines], 'Quote sent to the buyer.') !== null) {
            $this->quote = null;
        }
    }

    public function decline(): void
    {
        $this->submit('rfq', "marketplace/rfqs/{$this->openId}/decline", ['reason' => $this->reason ?: 'Not able to supply'], 'Invitation declined.');
    }

    public function acceptQuote(string $quoteId): void
    {
        $result = $this->attempt(fn ($api) => $api->post("marketplace/quotes/{$quoteId}/accept"), 'Quote accepted: an order was created.');
        if ($result !== null) {
            $this->redirectRoute('marketplace.orders', ['open' => $result['data']['id']], navigate: false);
        }
    }

    public function render()
    {
        $result = $this->api()->get('marketplace/rfqs', ['as' => $this->as, 'page' => $this->page]);
        $detail = $this->openId ? ($this->read("marketplace/rfqs/{$this->openId}")['data'] ?? null) : null;
        $comparison = $detail && $detail['your_side'] === 'buyer' && $detail['status'] !== 'draft' ? ($this->read("marketplace/rfqs/{$this->openId}/comparison")['data'] ?? null) : null;
        $suppliers = $this->doc ? collect($this->read('marketplace/relationships', ['as' => 'buyer', 'status' => 'approved'])['data'] ?? [])->mapWithKeys(fn ($r) => [$r['supplier']['id'] => $r['supplier']['name']]) : collect();

        return view('livewire.marketplace.rfqs', [
            'rows' => $result['data'] ?? [],
            'meta' => $result,
            'me' => app(TenantContext::class)->id(),
            'detail' => $detail,
            'comparison' => $comparison,
            'suppliers' => $suppliers,
            'warehouses' => $this->warehouseOptions(),
            'names' => $this->productNames(collect($detail['lines'] ?? [])->pluck('product_id')),
            'can' => ['manage' => $this->can('marketplace.rfq.manage'), 'respond' => $this->can('marketplace.quotes.respond'), 'place' => $this->can('marketplace.orders.place')],
        ]);
    }
}

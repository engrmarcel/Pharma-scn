<?php

namespace App\Livewire\Sales;

use App\Livewire\Concerns\EditsDocumentLines;
use App\Livewire\WebPage;
use Livewire\Attributes\Title;

/** Quotations: priced offers to customers that convert into sales orders. */
#[Title('Quotations')]
class Quotations extends WebPage
{
    use EditsDocumentLines;

    public const ROUTE = 'sales.quotations';

    public ?array $doc = null;

    public ?string $openId = null;

    public function create(): void
    {
        $this->resetErrorBag();
        $this->doc = ['customer_id' => '', 'warehouse_id' => '', 'valid_until' => business_today()->addDays(14)->toDateString(), 'notes' => '', 'lines' => [$this->blankLine()]];
    }

    public function save(): void
    {
        $result = $this->submit('doc', 'quotations', ['customer_id' => $this->doc['customer_id'], 'warehouse_id' => $this->doc['warehouse_id'],
            'valid_until' => $this->doc['valid_until'] ?: null, 'notes' => $this->doc['notes'] ?: null,
            'lines' => $this->linesPayload(['product_id', 'package_level_code', 'quantity', 'unit_price', 'discount_percent'])], 'Quotation created.');
        if ($result !== null) {
            $this->doc = null;
            $this->openId = $result['data']['id'];
        }
    }

    public function convert(): void
    {
        $result = $this->attempt(fn ($api) => $api->post("quotations/{$this->openId}/convert"), 'Sales order created from the quotation.');
        if ($result !== null) {
            $this->redirectRoute('sales.orders', ['open' => $result['data']['id'] ?? null], navigate: false);
        }
    }

    public function render()
    {
        $result = $this->api()->get('quotations', ['page' => $this->page]);
        $rows = $result['data'] ?? [];
        $detail = $this->openId ? collect($rows)->firstWhere('id', $this->openId) : null;

        return view('livewire.sales.quotations', [
            'rows' => $rows,
            'meta' => $result,
            'detail' => $detail,
            'customers' => collect($this->read('customers')['data'] ?? [])->pluck('name', 'id'),
            'warehouses' => $this->warehouseOptions(),
            'names' => $this->productNames(collect($detail['lines'] ?? [])->pluck('product_id')),
            'can' => ['quote' => $this->can('sales.quote'), 'order' => $this->can('sales.order')],
        ]);
    }
}

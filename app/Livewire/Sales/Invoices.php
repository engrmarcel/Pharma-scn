<?php

namespace App\Livewire\Sales;

use App\Livewire\WebPage;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

/** Customer invoices with their open balance and credit notes (returns, price adjustments). */
#[Title('Sales invoices')]
class Invoices extends WebPage
{
    public const ROUTE = 'sales.invoices';

    #[Url]
    public string $paymentStatus = '';

    #[Url]
    public string $customer = '';

    #[Url(as: 'open')]
    public ?string $openId = null;

    public ?array $credit = null;

    protected array $filters = ['paymentStatus', 'customer'];

    public function startCredit(): void
    {
        $inv = $this->api()->get("sales-invoices/{$this->openId}")['data'];
        $this->resetErrorBag();
        $this->credit = ['reason_code' => 'return', 'notes' => '', 'return_warehouse_id' => $inv['warehouse_id'] ?? '',
            'lines' => collect($inv['lines'])->mapWithKeys(fn ($l) => [$l['id'] => ['quantity' => '', 'restock_state' => 'quarantined']])->all()];
    }

    public function saveCredit(): void
    {
        $lines = collect($this->credit['lines'])->filter(fn ($l) => $l['quantity'] !== '' && (float) $l['quantity'] > 0)
            ->map(fn ($l, $id) => ['sales_invoice_line_id' => $id, 'quantity' => $l['quantity']] + ($this->credit['reason_code'] === 'return' ? ['restock_state' => $l['restock_state']] : []))->values()->all();
        $data = ['reason_code' => $this->credit['reason_code'], 'notes' => $this->credit['notes'] ?: null, 'lines' => $lines]
            + ($this->credit['reason_code'] === 'return' && $this->credit['return_warehouse_id'] ? ['return_warehouse_id' => $this->credit['return_warehouse_id']] : []);
        if ($this->submit('credit', "sales-invoices/{$this->openId}/credit-notes", $data, 'Credit note issued.') !== null) {
            $this->credit = null;
        }
    }

    public function render()
    {
        $result = $this->api()->get('sales-invoices', ['payment_status' => $this->paymentStatus, 'customer_id' => $this->customer, 'page' => $this->page]);
        $detail = $this->openId ? ($this->read("sales-invoices/{$this->openId}")['data'] ?? null) : null;

        return view('livewire.sales.invoices', [
            'rows' => $result['data'] ?? [],
            'meta' => $result,
            'detail' => $detail,
            'customers' => collect($this->read('customers')['data'] ?? [])->pluck('name', 'id'),
            'warehouses' => $this->warehouseOptions(),
            'names' => $this->productNames(collect($detail['lines'] ?? [])->pluck('product_id')),
            'can' => ['credit' => $this->can('sales.credit'), 'pay' => $this->can('finance.payments.record')],
        ]);
    }
}

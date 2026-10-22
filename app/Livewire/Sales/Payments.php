<?php

namespace App\Livewire\Sales;

use App\Domain\Finance\Models\Payment;
use App\Livewire\WebPage;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

/** Receive a customer payment and allocate it to open invoices; recent customer payments. */
#[Title('Customer payments')]
class Payments extends WebPage
{
    public const ROUTE = 'sales.payments';

    #[Url]
    public string $customer = '';

    public array $form = ['amount' => '', 'method' => 'bank_transfer', 'payment_date' => '', 'reference' => '', 'notes' => ''];

    /** invoice id => amount allocated. */
    public array $allocations = [];

    public function mount(): void
    {
        $this->form['payment_date'] = business_today()->toDateString();
    }

    public function updatedCustomer(): void
    {
        $this->allocations = [];
        $this->page = 1;
    }

    /** Allocate the amount to the oldest open invoices first. */
    public function autoAllocate(): void
    {
        $left = (float) $this->form['amount'];
        $this->allocations = [];
        foreach ($this->openInvoices() as $inv) {
            if ($left <= 0) {
                break;
            }
            $take = min($left, (float) $inv['open']);
            $this->allocations[$inv['id']] = (string) round($take, 4);
            $left -= $take;
        }
    }

    public function receive(): void
    {
        $allocations = collect($this->allocations)->filter(fn ($a) => $a !== '' && (float) $a > 0)->map(fn ($a, $id) => ['sales_invoice_id' => $id, 'amount' => $a])->values()->all();
        $result = $this->submit('payment', 'customer-payments', ['customer_id' => $this->customer] + array_filter($this->form, fn ($v) => $v !== '') + ['allocations' => $allocations], 'Payment received.');
        if ($result !== null) {
            $this->form = ['amount' => '', 'method' => 'bank_transfer', 'payment_date' => business_today()->toDateString(), 'reference' => '', 'notes' => ''];
            $this->allocations = [];
        }
    }

    private function openInvoices(): array
    {
        if ($this->customer === '') {
            return [];
        }
        $rows = collect($this->read('sales-invoices', ['customer_id' => $this->customer])['data'] ?? [])->where('status', 'issued')->whereIn('payment_status', ['unpaid', 'partial']);

        return $rows->map(fn ($i) => $i + ['open' => round((float) $i['total'] - (float) $i['amount_paid'] - (float) $i['amount_credited'], 4)])
            ->filter(fn ($i) => $i['open'] > 0)->sortBy('due_date')->values()->all();
    }

    public function render()
    {
        $payments = $this->read('finance/payments', ['party_type' => 'customer', 'party_id' => $this->customer, 'page' => $this->page], ['data' => null]);

        return view('livewire.sales.payments', [
            'customers' => collect($this->read('customers')['data'] ?? [])->pluck('name', 'id'),
            'invoices' => $this->openInvoices(),
            'methods' => Payment::METHODS,
            'payments' => $payments['data'],
            'meta' => $payments,
            'currency' => $this->currency(),
            'canRecord' => $this->can('finance.payments.record'),
        ]);
    }
}

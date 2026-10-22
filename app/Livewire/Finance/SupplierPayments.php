<?php

namespace App\Livewire\Finance;

use App\Domain\Finance\Models\Payment;
use App\Livewire\WebPage;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

/** Payments: pay suppliers against approved invoices, allocate payments on account, review all payments. */
#[Title('Payments')]
class SupplierPayments extends WebPage
{
    public const ROUTE = 'finance.payments';

    #[Url]
    public string $party = '';

    public ?array $pay = null;

    /** Unallocated payment being allocated: id, supplier_id, allocations. */
    public ?array $allocating = null;

    protected array $filters = ['party'];

    public function startPay(): void
    {
        $this->resetErrorBag();
        $this->pay = ['supplier_id' => '', 'amount' => '', 'method' => 'bank_transfer', 'payment_date' => business_today()->toDateString(), 'reference' => '', 'account_id' => '', 'notes' => '', 'allocations' => []];
    }

    public function savePay(): void
    {
        $allocations = collect($this->pay['allocations'])->filter(fn ($a) => $a !== '' && (float) $a > 0)->map(fn ($a, $id) => ['supplier_invoice_id' => $id, 'amount' => $a])->values()->all();
        $data = array_filter(collect($this->pay)->except('allocations')->all(), fn ($v) => $v !== '') + ['allocations' => $allocations];
        if ($this->submit('pay', 'finance/payments/supplier', $data, 'Supplier payment recorded.') !== null) {
            $this->pay = null;
        }
    }

    public function startAllocate(string $id, string $supplierId): void
    {
        $this->resetErrorBag();
        $this->allocating = ['id' => $id, 'supplier_id' => $supplierId, 'allocations' => []];
    }

    public function saveAllocate(): void
    {
        $allocations = collect($this->allocating['allocations'])->filter(fn ($a) => $a !== '' && (float) $a > 0)->map(fn ($a, $id) => ['supplier_invoice_id' => $id, 'amount' => $a])->values()->all();
        if ($this->submit('allocate', "finance/payments/{$this->allocating['id']}/allocations", ['allocations' => $allocations], 'Payment allocated.') !== null) {
            $this->allocating = null;
        }
    }

    private function openSupplierInvoices(?string $supplierId): array
    {
        if (! $supplierId) {
            return [];
        }

        return collect($this->read('supplier-invoices', ['supplier_id' => $supplierId, 'status' => 'approved'])['data'] ?? [])
            ->map(fn ($i) => $i + ['open' => round((float) $i['total'] - (float) $i['amount_paid'], 4)])->filter(fn ($i) => $i['open'] > 0)->values()->all();
    }

    public function render()
    {
        $result = $this->api()->get('finance/payments', ['party_type' => $this->party, 'page' => $this->page]);
        $suppliers = collect($this->read('suppliers')['data'] ?? [])->pluck('name', 'id');
        $customers = collect($this->read('customers')['data'] ?? [])->pluck('name', 'id');

        return view('livewire.finance.supplier-payments', [
            'rows' => $result['data'] ?? [],
            'meta' => $result,
            'suppliers' => $suppliers,
            'customers' => $customers,
            'methods' => Payment::METHODS,
            'cashAccounts' => collect($this->read('finance/accounts')['data'] ?? [])->where('type', 'asset')->where('status', 'active')->mapWithKeys(fn ($a) => [$a['id'] => $a['code'].' · '.$a['name']]),
            'openInvoices' => $this->openSupplierInvoices($this->pay['supplier_id'] ?? ($this->allocating['supplier_id'] ?? null)),
            'currency' => $this->currency(),
            'canRecord' => $this->can('finance.payments.record'),
        ]);
    }
}

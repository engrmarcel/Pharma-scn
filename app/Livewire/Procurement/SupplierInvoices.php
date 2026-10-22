<?php

namespace App\Livewire\Procurement;

use App\Livewire\WebPage;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

/** Supplier invoices matched against orders and receipts; approve (with an override reason when unmatched) or cancel. */
#[Title('Supplier invoices')]
class SupplierInvoices extends WebPage
{
    public const ROUTE = 'procurement.invoices';

    #[Url]
    public string $status = '';

    #[Url]
    public string $match = '';

    /** Purchase order to invoice (from the order screen). */
    #[Url]
    public string $order = '';

    public ?array $doc = null;

    public ?string $openId = null;

    public string $reason = '';

    protected array $filters = ['status', 'match'];

    public function mount(): void
    {
        if ($this->order !== '') {
            $this->create($this->order);
        }
    }

    public function create(?string $orderId = null): void
    {
        $this->resetErrorBag();
        $this->doc = ['supplier_id' => '', 'purchase_order_id' => '', 'supplier_invoice_number' => '', 'invoice_date' => business_today()->toDateString(), 'due_date' => '', 'lines' => []];
        if ($orderId && ($po = $this->read("purchase-orders/{$orderId}")['data'] ?? null)) {
            $names = $this->productNames(array_column($po['lines'], 'product_id'));
            $this->doc['supplier_id'] = $po['supplier_id'];
            $this->doc['purchase_order_id'] = $po['id'];
            foreach ($po['lines'] as $l) {
                $perUnit = (float) $l['base_quantity'] / max(0.0001, (float) $l['quantity']);
                $billable = max(0, ((float) $l['received_base_quantity'] - (float) $l['invoiced_base_quantity']) / $perUnit);
                $this->doc['lines'][] = ['purchase_order_line_id' => $l['id'], 'description' => ($names[$l['product_id']] ?? '').' · '.$l['package_level_code'],
                    'quantity' => $billable > 0 ? (string) round($billable, 4) : '', 'unit_price' => (string) (float) $l['unit_price'], 'tax_rate' => (string) (float) $l['tax_rate']];
            }
        }
        if ($this->doc['lines'] === []) {
            $this->addLine();
        }
    }

    public function addLine(): void
    {
        $this->doc['lines'][] = ['purchase_order_line_id' => null, 'description' => '', 'quantity' => '1', 'unit_price' => '', 'tax_rate' => ''];
    }

    public function removeLine(int $i): void
    {
        unset($this->doc['lines'][$i]);
        $this->doc['lines'] = array_values($this->doc['lines']);
    }

    public function save(): void
    {
        $lines = collect($this->doc['lines'])->filter(fn ($l) => $l['quantity'] !== '')
            ->map(fn ($l) => array_filter($l, fn ($v, $k) => $v !== '' && $v !== null && ! ($k === 'description' && $l['purchase_order_line_id']), ARRAY_FILTER_USE_BOTH))->values()->all();
        $data = array_filter(collect($this->doc)->except('lines')->all(), fn ($v) => $v !== '') + ['lines' => $lines];
        $result = $this->submit('doc', 'supplier-invoices', $data, 'Invoice recorded and matched.');
        if ($result !== null) {
            $this->doc = null;
            $this->order = '';
            $this->openId = $result['data']['id'];
        }
    }

    public function open(string $id): void
    {
        $this->openId = $id;
        $this->reason = '';
    }

    public function approve(): void
    {
        $this->submit('approve', "supplier-invoices/{$this->openId}/approve", array_filter(['override_reason' => $this->reason]), 'Invoice approved and posted to payables.');
    }

    public function cancel(): void
    {
        $this->submit('approve', "supplier-invoices/{$this->openId}/cancel", ['reason' => $this->reason], 'Invoice cancelled.');
    }

    public function render()
    {
        $result = $this->api()->get('supplier-invoices', ['status' => $this->status, 'match_status' => $this->match, 'page' => $this->page]);
        $rows = $result['data'] ?? [];
        $suppliers = collect($this->read('suppliers')['data'] ?? [])->pluck('name', 'id');
        $orders = collect($this->read('purchase-orders', ['supplier_id' => $this->doc['supplier_id'] ?? ''])['data'] ?? [])
            ->whereIn('status', ['sent', 'partially_received', 'received', 'closed'])->mapWithKeys(fn ($o) => [$o['id'] => $o['number']]);

        return view('livewire.procurement.supplier-invoices', [
            'rows' => $rows,
            'meta' => $result,
            'detail' => $this->openId ? collect($rows)->firstWhere('id', $this->openId) : null,
            'suppliers' => $suppliers,
            'orders' => $orders,
            'can' => ['record' => $this->can('procurement.invoice'), 'approve' => $this->can('procurement.approve')],
        ]);
    }
}

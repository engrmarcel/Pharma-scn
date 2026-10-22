<?php

namespace App\Livewire\Procurement;

use App\Livewire\WebPage;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

/** Goods received against purchase orders, batch by batch; posting adds stock and the payable accrual. */
#[Title('Goods receipts')]
class GoodsReceipts extends WebPage
{
    public const ROUTE = 'procurement.receipts';

    /** Purchase order being received (also filters the list). */
    #[Url]
    public string $order = '';

    public array $form = ['supplier_delivery_note' => '', 'notes' => ''];

    public array $lines = [];

    public ?array $viewing = null;

    protected array $filters = ['order'];

    public function mount(): void
    {
        if ($this->order !== '') {
            $this->startReceipt();
        }
    }

    public function updatedOrder(): void
    {
        $this->page = 1;
        $this->startReceipt();
    }

    public function startReceipt(): void
    {
        $this->resetErrorBag();
        $this->lines = [];
        if ($this->order === '') {
            return;
        }
        $po = $this->read("purchase-orders/{$this->order}")['data'] ?? null;
        if ($po === null || ! in_array($po['status'], ['approved', 'sent', 'partially_received'], true)) {
            return;
        }
        $names = $this->productNames(array_column($po['lines'], 'product_id'));
        foreach ($po['lines'] as $l) {
            $perUnit = (float) $l['base_quantity'] / max(0.0001, (float) $l['quantity']);
            $outstanding = max(0, ((float) $l['base_quantity'] - (float) $l['received_base_quantity']) / $perUnit);
            $this->lines[] = ['purchase_order_line_id' => $l['id'], 'name' => $names[$l['product_id']] ?? '', 'pack' => $l['package_level_code'],
                'outstanding' => round($outstanding, 4), 'batch_number' => '', 'expiry_date' => '', 'manufacturing_date' => '',
                'quantity' => $outstanding > 0 ? (string) round($outstanding, 4) : '', 'quality_status' => 'released'];
        }
    }

    public function receive(): void
    {
        $lines = collect($this->lines)->filter(fn ($l) => $l['quantity'] !== '' && (float) $l['quantity'] > 0)
            ->map(fn ($l) => array_filter(collect($l)->only(['purchase_order_line_id', 'batch_number', 'expiry_date', 'manufacturing_date', 'quantity', 'quality_status'])->all(), fn ($v) => $v !== ''))
            ->values()->all();
        $result = $this->submit('receipt', 'goods-receipts', ['purchase_order_id' => $this->order, 'lines' => $lines] + array_filter($this->form), 'Goods received and added to stock.');
        if ($result !== null) {
            $this->form = ['supplier_delivery_note' => '', 'notes' => ''];
            $this->startReceipt();
        }
    }

    public function render()
    {
        $result = $this->api()->get('goods-receipts', ['purchase_order_id' => $this->order, 'page' => $this->page]);
        $orders = collect($this->read('purchase-orders', ['page' => 1])['data'] ?? [])->whereIn('status', ['approved', 'sent', 'partially_received']);
        if ($this->order !== '' && ! $orders->contains('id', $this->order) && ($po = $this->read("purchase-orders/{$this->order}")['data'] ?? null)) {
            $orders->push($po);
        }
        $suppliers = collect($this->read('suppliers')['data'] ?? [])->pluck('name', 'id');

        return view('livewire.procurement.goods-receipts', [
            'rows' => $result['data'] ?? [],
            'meta' => $result,
            'orders' => $orders->mapWithKeys(fn ($o) => [$o['id'] => $o['number'].' · '.($suppliers[$o['supplier_id']] ?? '').' · '.str_replace('_', ' ', $o['status'])]),
            'suppliers' => $suppliers,
            'warehouses' => $this->warehouseOptions(),
            'names' => $this->productNames(collect($this->viewing['lines'] ?? [])->pluck('product_id')),
            'batches' => $this->batchLabels(collect($this->viewing['lines'] ?? [])->pluck('batch_id')),
            'canReceive' => $this->can('inventory.receive'),
        ]);
    }
}

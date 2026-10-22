<?php

namespace App\Livewire\Sales;

use App\Livewire\Concerns\EditsDocumentLines;
use App\Livewire\WebPage;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

/** Sales orders: draft, confirm (with approval when credit or discount rules need it), deliver by FEFO, invoice, close. */
#[Title('Sales orders')]
class SalesOrders extends WebPage
{
    use EditsDocumentLines;

    public const ROUTE = 'sales.orders';

    #[Url]
    public string $status = '';

    #[Url]
    public string $customer = '';

    #[Url(as: 'open')]
    public ?string $openId = null;

    public ?array $doc = null;

    public ?array $reasoned = null;

    /** line id => quantity to deliver now. */
    public ?array $delivering = null;

    public string $deliveryNotes = '';

    protected array $filters = ['status', 'customer'];

    public function create(): void
    {
        $this->resetErrorBag();
        $this->doc = ['id' => null, 'customer_id' => $this->customer, 'warehouse_id' => '', 'requested_date' => '', 'notes' => '', 'lines' => [$this->blankLine()]];
    }

    public function edit(): void
    {
        $o = $this->api()->get("sales-orders/{$this->openId}")['data'];
        $this->resetErrorBag();
        $this->doc = ['id' => $o['id'], 'customer_id' => $o['customer_id'], 'warehouse_id' => $o['warehouse_id'], 'requested_date' => '', 'notes' => $o['notes'] ?? '', 'lines' => $this->linesFromApi($o['lines'])];
    }

    public function save(): void
    {
        $lines = $this->linesPayload(['product_id', 'package_level_code', 'quantity', 'unit_price', 'discount_percent']);
        $result = $this->doc['id']
            ? $this->submit('doc', "sales-orders/{$this->doc['id']}", ['lines' => $lines], 'Order updated.', 'patch')
            : $this->submit('doc', 'sales-orders', ['customer_id' => $this->doc['customer_id'], 'warehouse_id' => $this->doc['warehouse_id'],
                'requested_date' => $this->doc['requested_date'] ?: null, 'notes' => $this->doc['notes'] ?: null, 'lines' => $lines], 'Sales order saved as a draft.');
        if ($result !== null) {
            $this->openId = $result['data']['id'];
            $this->doc = null;
        }
    }

    public function open(string $id): void
    {
        $this->openId = $id;
        $this->reasoned = null;
        $this->delivering = null;
    }

    public function act(string $action): void
    {
        if (in_array($action, ['reject', 'cancel', 'close'], true) && $this->reasoned === null) {
            $this->resetErrorBag();
            $this->reasoned = ['action' => $action, 'reason' => ''];

            return;
        }
        $messages = ['confirm' => 'Order confirmed.', 'approve' => 'Order approved.', 'reject' => 'Order rejected.', 'cancel' => 'Order cancelled.', 'close' => 'Order closed.'];
        if ($this->submit('reasoned', "sales-orders/{$this->openId}/{$action}", $this->reasoned ? ['reason' => $this->reasoned['reason']] : [], $messages[$action] ?? null) !== null) {
            $this->reasoned = null;
        }
    }

    public function startDelivery(): void
    {
        $o = $this->api()->get("sales-orders/{$this->openId}")['data'];
        $this->resetErrorBag();
        $this->deliveryNotes = '';
        $this->delivering = collect($o['lines'])->mapWithKeys(function ($l) {
            $perUnit = (float) $l['base_quantity'] / max(0.0001, (float) $l['quantity']);
            $open = max(0, ((float) $l['base_quantity'] - (float) $l['delivered_base_quantity']) / $perUnit);

            return [$l['id'] => $open > 0 ? (string) round($open, 4) : ''];
        })->all();
    }

    public function deliver(): void
    {
        $lines = collect($this->delivering)->filter(fn ($q) => $q !== '' && (float) $q > 0)->map(fn ($q, $id) => ['sales_order_line_id' => $id, 'quantity' => $q])->values()->all();
        if ($this->submit('delivery', "sales-orders/{$this->openId}/deliveries", ['lines' => $lines, 'notes' => $this->deliveryNotes ?: null], 'Delivered: stock issued first-expiry-first-out.') !== null) {
            $this->delivering = null;
        }
    }

    public function invoice(): void
    {
        $this->attempt(fn ($api) => $api->post("sales-orders/{$this->openId}/invoice"), 'Invoice issued for what was delivered.');
    }

    public function render()
    {
        $result = $this->api()->get('sales-orders', ['status' => $this->status, 'customer_id' => $this->customer, 'page' => $this->page]);
        $detail = $this->openId ? ($this->read("sales-orders/{$this->openId}")['data'] ?? null) : null;
        $customers = collect($this->read('customers')['data'] ?? [])->pluck('name', 'id');

        return view('livewire.sales.sales-orders', [
            'rows' => $result['data'] ?? [],
            'meta' => $result,
            'detail' => $detail,
            'customers' => $customers,
            'warehouses' => $this->warehouseOptions(),
            'names' => $this->productNames(collect($detail['lines'] ?? [])->pluck('product_id')),
            'batches' => $this->batchLabels(collect($detail['deliveries'] ?? [])->pluck('lines')->flatten(1)->pluck('batch_id')),
            'can' => ['order' => $this->can('sales.order'), 'approve' => $this->can('sales.approve'), 'deliver' => $this->can('sales.deliver'), 'invoice' => $this->can('sales.invoice')],
        ]);
    }
}

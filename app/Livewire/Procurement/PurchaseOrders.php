<?php

namespace App\Livewire\Procurement;

use App\Livewire\Concerns\EditsDocumentLines;
use App\Livewire\WebPage;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

/** Purchase orders: draft, submit, approve or reject, send, receive, invoice, cancel or close. */
#[Title('Purchase orders')]
class PurchaseOrders extends WebPage
{
    use EditsDocumentLines;

    public const ROUTE = 'procurement.orders';

    #[Url]
    public string $status = '';

    #[Url]
    public string $supplier = '';

    #[Url(as: 'open')]
    public ?string $openId = null;

    /** Order being created or edited (id null = new). */
    public ?array $doc = null;

    /** Action that needs a reason: reject, cancel, close. */
    public ?array $reasoned = null;

    protected array $filters = ['status', 'supplier'];

    protected function linePriceField(): string
    {
        return 'cost';
    }

    public function create(): void
    {
        $this->resetErrorBag();
        $this->doc = ['id' => null, 'supplier_id' => '', 'warehouse_id' => '', 'expected_date' => '', 'notes' => '', 'lines' => [$this->blankLine()]];
    }

    public function edit(): void
    {
        $o = $this->api()->get("purchase-orders/{$this->openId}")['data'];
        $this->resetErrorBag();
        $this->doc = ['id' => $o['id'], 'supplier_id' => $o['supplier_id'], 'warehouse_id' => $o['warehouse_id'], 'expected_date' => $o['expected_date'] ? substr($o['expected_date'], 0, 10) : '',
            'notes' => $o['notes'] ?? '', 'lines' => $this->linesFromApi($o['lines'])];
    }

    public function save(): void
    {
        $data = ['expected_date' => $this->doc['expected_date'] ?: null, 'notes' => $this->doc['notes'] ?: null, 'lines' => $this->linesPayload()];
        $result = $this->doc['id']
            ? $this->submit('doc', "purchase-orders/{$this->doc['id']}", $data, 'Order updated.', 'patch')
            : $this->submit('doc', 'purchase-orders', $data + ['supplier_id' => $this->doc['supplier_id'], 'warehouse_id' => $this->doc['warehouse_id']], 'Purchase order saved as a draft.');
        if ($result !== null) {
            $this->openId = $result['data']['id'];
            $this->doc = null;
        }
    }

    public function open(string $id): void
    {
        $this->openId = $id;
        $this->reasoned = null;
    }

    public function act(string $action): void
    {
        if (in_array($action, ['reject', 'cancel', 'close'], true) && $this->reasoned === null) {
            $this->resetErrorBag();
            $this->reasoned = ['action' => $action, 'reason' => ''];

            return;
        }
        $messages = ['submit' => 'Order submitted.', 'approve' => 'Order approved.', 'send' => 'Order marked as sent to the supplier.',
            'reject' => 'Order rejected.', 'cancel' => 'Order cancelled.', 'close' => 'Order closed.'];
        $body = $this->reasoned ? ['reason' => $this->reasoned['reason']] : [];
        if ($this->submit('reasoned', "purchase-orders/{$this->openId}/{$action}", $body, $messages[$action] ?? null) !== null) {
            $this->reasoned = null;
        }
    }

    public function render()
    {
        $result = $this->api()->get('purchase-orders', ['status' => $this->status, 'supplier_id' => $this->supplier, 'page' => $this->page]);
        $detail = $this->openId ? ($this->read("purchase-orders/{$this->openId}")['data'] ?? null) : null;
        $suppliers = collect($this->read('suppliers')['data'] ?? [])->pluck('name', 'id');

        return view('livewire.procurement.purchase-orders', [
            'rows' => $result['data'] ?? [],
            'meta' => $result,
            'detail' => $detail,
            'suppliers' => $suppliers,
            'warehouses' => $this->warehouseOptions(),
            'names' => $this->productNames(collect($detail['lines'] ?? [])->pluck('product_id')),
            'can' => ['order' => $this->can('procurement.order'), 'approve' => $this->can('procurement.approve'), 'receive' => $this->can('inventory.receive'), 'invoice' => $this->can('procurement.invoice')],
        ]);
    }
}

<?php

namespace App\Livewire\Procurement;

use App\Livewire\Concerns\EditsDocumentLines;
use App\Livewire\WebPage;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

/** Purchase requisitions: raise, submit, approve or reject, convert into a purchase order. */
#[Title('Purchase requisitions')]
class Requisitions extends WebPage
{
    use EditsDocumentLines;

    public const ROUTE = 'procurement.requisitions';

    #[Url]
    public string $status = '';

    public ?array $doc = null;

    public ?string $openId = null;

    public string $decisionNotes = '';

    public string $convertSupplier = '';

    protected array $filters = ['status'];

    protected function linePriceField(): string
    {
        return 'cost';
    }

    public function create(array $prefill = []): void
    {
        $this->resetErrorBag();
        $this->doc = ['warehouse_id' => $prefill['warehouse_id'] ?? '', 'needed_by' => '', 'notes' => '', 'lines' => $prefill['lines'] ?? [$this->blankLine()]];
    }

    public function save(): void
    {
        $data = ['warehouse_id' => $this->doc['warehouse_id'], 'needed_by' => $this->doc['needed_by'] ?: null, 'notes' => $this->doc['notes'] ?: null,
            'lines' => $this->linesPayload(['product_id', 'package_level_code', 'quantity'])];
        $result = $this->submit('doc', 'purchase-requisitions', $data, 'Requisition saved as a draft.');
        if ($result !== null) {
            $this->doc = null;
            $this->openId = $result['data']['id'];
        }
    }

    public function open(string $id): void
    {
        $this->openId = $id;
        $this->decisionNotes = '';
        $this->convertSupplier = '';
    }

    public function act(string $action): void
    {
        match ($action) {
            'submit' => $this->attempt(fn ($api) => $api->post("purchase-requisitions/{$this->openId}/submit"), 'Requisition submitted for approval.'),
            'approve', 'reject' => $this->submit('decision', "purchase-requisitions/{$this->openId}/{$action}", ['notes' => $this->decisionNotes ?: null], 'Requisition '.($action === 'approve' ? 'approved' : 'rejected').'.'),
            'convert' => $this->convert(),
        };
    }

    private function convert(): void
    {
        $result = $this->submit('convert', "purchase-requisitions/{$this->openId}/convert", array_filter(['supplier_id' => $this->convertSupplier]), 'Purchase order created from the requisition.');
        if ($result !== null) {
            $this->redirectRoute('procurement.orders', ['open' => $result['data']['id'] ?? null], navigate: false);
        }
    }

    public function render()
    {
        $result = $this->api()->get('purchase-requisitions', ['status' => $this->status, 'page' => $this->page]);
        $rows = $result['data'] ?? [];
        $detail = $this->openId ? collect($rows)->firstWhere('id', $this->openId) : null;

        return view('livewire.procurement.requisitions', [
            'rows' => $rows,
            'meta' => $result,
            'detail' => $detail,
            'warehouses' => $this->warehouseOptions(),
            'suppliers' => collect($this->read('suppliers', ['status' => 'active'])['data'] ?? [])->pluck('name', 'id'),
            'names' => $this->productNames(collect($detail['lines'] ?? [])->pluck('product_id')),
            'can' => ['raise' => $this->can('procurement.requisition'), 'approve' => $this->can('procurement.approve'), 'order' => $this->can('procurement.order')],
        ]);
    }
}

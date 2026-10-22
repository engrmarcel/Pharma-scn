<?php

namespace App\Livewire\Procurement;

use App\Domain\Procurement\Services\SupplierReturnService;
use App\Livewire\WebPage;
use Livewire\Attributes\Title;

/** Returns to suppliers: damaged, expired, recalled or wrong stock leaves the warehouse with a reason. */
#[Title('Supplier returns')]
class SupplierReturns extends WebPage
{
    public const ROUTE = 'procurement.returns';

    public ?array $doc = null;

    public ?array $viewing = null;

    public function create(): void
    {
        $this->resetErrorBag();
        $this->doc = ['supplier_id' => '', 'warehouse_id' => '', 'reason_code' => 'damaged', 'notes' => '', 'lines' => [['batch_id' => '', 'base_quantity' => '', 'stock_state' => 'available']]];
    }

    public function addLine(): void
    {
        $this->doc['lines'][] = ['batch_id' => '', 'base_quantity' => '', 'stock_state' => 'available'];
    }

    public function removeLine(int $i): void
    {
        unset($this->doc['lines'][$i]);
        $this->doc['lines'] = array_values($this->doc['lines']);
    }

    public function save(): void
    {
        $data = $this->doc;
        $data['notes'] = $data['notes'] ?: null;
        if ($this->submit('doc', 'supplier-returns', $data, 'Return posted: stock has left the warehouse.') !== null) {
            $this->doc = null;
        }
    }

    public function render()
    {
        $result = $this->api()->get('supplier-returns', ['page' => $this->page]);
        $rows = $result['data'] ?? [];
        $balances = ($this->doc['warehouse_id'] ?? '') !== '' ? ($this->read('inventory/balances', ['warehouse_id' => $this->doc['warehouse_id']])['data'] ?? []) : [];

        return view('livewire.procurement.supplier-returns', [
            'rows' => $rows,
            'meta' => $result,
            'suppliers' => collect($this->read('suppliers')['data'] ?? [])->pluck('name', 'id'),
            'warehouses' => $this->warehouseOptions(),
            'reasons' => SupplierReturnService::REASONS,
            'balances' => $balances,
            'names' => $this->productNames(array_column($balances, 'product_id')),
            'batches' => $this->batchLabels(collect($this->viewing['lines'] ?? [])->pluck('batch_id')),
            'canReturn' => $this->can('procurement.return'),
        ]);
    }
}

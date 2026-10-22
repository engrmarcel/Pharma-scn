<?php

namespace App\Livewire\Inventory;

use App\Domain\Inventory\Services\StockDocumentService;
use App\Livewire\WebPage;
use Livewire\Attributes\Title;

/** Stock adjustments with a reason: damage, expiry, theft, found stock, count corrections. */
#[Title('Stock adjustments')]
class Adjustments extends WebPage
{
    public const ROUTE = 'inventory.adjustments';

    public ?array $form = null;

    public ?array $viewing = null;

    public function create(): void
    {
        $this->resetErrorBag();
        $this->form = ['warehouse_id' => '', 'reason_code' => 'damage', 'notes' => '', 'lines' => [['batch_id' => '', 'stock_state' => 'available', 'quantity_delta' => '']]];
    }

    public function addLine(): void
    {
        $this->form['lines'][] = ['batch_id' => '', 'stock_state' => 'available', 'quantity_delta' => ''];
    }

    public function removeLine(int $i): void
    {
        unset($this->form['lines'][$i]);
        $this->form['lines'] = array_values($this->form['lines']);
    }

    public function save(): void
    {
        $data = $this->form;
        $data['notes'] = $data['notes'] ?: null;
        if ($this->submit('form', 'stock-adjustments', $data, 'Adjustment posted.') !== null) {
            $this->form = null;
        }
    }

    public function render()
    {
        $result = $this->api()->get('stock-adjustments', ['page' => $this->page]);
        $rows = $result['data'] ?? [];
        $balances = ($this->form['warehouse_id'] ?? '') !== '' ? ($this->read('inventory/balances', ['warehouse_id' => $this->form['warehouse_id']])['data'] ?? []) : [];
        $batchIds = array_merge(collect($rows)->pluck('lines')->flatten(1)->pluck('batch_id')->all(), array_column($balances, 'batch_id'), collect($this->viewing['lines'] ?? [])->pluck('batch_id')->all());

        return view('livewire.inventory.adjustments', [
            'rows' => $rows,
            'meta' => $result,
            'warehouses' => $this->warehouseOptions(),
            'reasons' => StockDocumentService::ADJUSTMENT_REASONS,
            'balances' => $balances,
            'names' => $this->productNames(array_column($balances, 'product_id')),
            'batches' => $this->batchLabels($batchIds),
            'canAdjust' => $this->can('inventory.adjust'),
        ]);
    }
}

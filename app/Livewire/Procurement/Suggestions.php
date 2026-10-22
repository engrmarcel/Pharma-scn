<?php

namespace App\Livewire\Procurement;

use App\Livewire\WebPage;
use Livewire\Attributes\Title;

/** Reorder suggestions from stock and reorder levels; selected rows become a draft requisition. */
#[Title('Reorder suggestions')]
class Suggestions extends WebPage
{
    public const ROUTE = 'procurement.suggestions';

    /** product_id => selected. */
    public array $selected = [];

    public string $warehouse = '';

    public function raise(): void
    {
        $rows = collect($this->api()->get('procurement/suggestions')['data'] ?? [])->keyBy('product_id');
        $lines = collect($this->selected)->filter()->keys()->map(fn ($id) => array_filter([
            'product_id' => $id, 'quantity' => $rows[$id]['suggested_base_quantity'] ?? '1',
            'suggested_supplier_id' => $rows[$id]['preferred_supplier_id'] ?? null,
        ]))->values()->all();
        if ($lines === []) {
            $this->notify('Select at least one product.', 'warning');

            return;
        }
        $result = $this->submit('raise', 'purchase-requisitions', ['warehouse_id' => $this->warehouse, 'lines' => $lines, 'notes' => 'Raised from reorder suggestions.'], 'Draft requisition created.');
        if ($result !== null) {
            $this->selected = [];
            $this->redirectRoute('procurement.requisitions', navigate: false);
        }
    }

    public function render()
    {
        $data = $this->api()->get('procurement/suggestions');

        return view('livewire.procurement.suggestions', [
            'rows' => $data['data'] ?? [],
            'note' => $data['meta']['note'] ?? null,
            'warehouses' => $this->warehouseOptions(),
            'suppliers' => collect($this->read('suppliers')['data'] ?? [])->pluck('name', 'id'),
            'canRaise' => $this->can('procurement.requisition'),
        ]);
    }
}

<?php

namespace App\Livewire\Inventory;

use App\Livewire\WebPage;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

/** The stock ledger: every posted movement, filterable by warehouse, type and dates. */
#[Title('Stock movements')]
class Movements extends WebPage
{
    public const ROUTE = 'inventory.movements';

    #[Url]
    public string $warehouse = '';

    #[Url]
    public string $type = '';

    public string $from = '';

    public string $to = '';

    protected array $filters = ['warehouse', 'type', 'from', 'to'];

    public function render()
    {
        $result = $this->api()->get('inventory/movements', ['warehouse_id' => $this->warehouse, 'movement_type' => $this->type, 'from' => $this->from, 'to' => $this->to, 'page' => $this->page]);
        $rows = $result['data'] ?? [];

        return view('livewire.inventory.movements', [
            'rows' => $rows,
            'meta' => $result,
            'warehouses' => $this->warehouseOptions(),
            'names' => $this->productNames(array_column($rows, 'product_id')),
            'batches' => $this->batchLabels(array_column($rows, 'batch_id')),
        ]);
    }
}

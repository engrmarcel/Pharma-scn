<?php

namespace App\Livewire\Inventory;

use App\Livewire\WebPage;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

/** Stock on hand by product and warehouse, with low stock, expiry and valuation views and a batch drill-down. */
#[Title('Stock on hand')]
class Stock extends WebPage
{
    public const ROUTE = 'inventory.stock';

    #[Url]
    public string $tab = 'levels';

    #[Url]
    public string $warehouse = '';

    public int $days = 90;

    /** [product_id, warehouse_id] of the open drill-down. */
    public ?array $drill = null;

    protected array $filters = ['warehouse', 'tab'];

    public function openDrill(string $productId, string $warehouseId): void
    {
        $this->drill = ['product_id' => $productId, 'warehouse_id' => $warehouseId];
    }

    public function render()
    {
        $data = match ($this->tab) {
            'low' => $this->read('inventory/low-stock'),
            'expiring' => $this->read('inventory/expiring', ['days' => $this->days]),
            'valuation' => $this->read('inventory/valuation', default: ['data' => null]),
            default => $this->api()->get('inventory/stock-levels', ['warehouse_id' => $this->warehouse, 'page' => $this->page]),
        };
        $rows = $data['data'] ?? [];
        if ($this->tab === 'expiring' && $this->warehouse !== '') {
            $rows = array_values(array_filter($rows, fn ($r) => $r['warehouse_id'] === $this->warehouse));
        }

        return view('livewire.inventory.stock', [
            'rows' => $rows,
            'meta' => $this->tab === 'levels' ? $data : [],
            'warehouses' => $this->warehouseOptions(),
            'drillRows' => $this->drill ? ($this->read('inventory/balances', $this->drill)['data'] ?? []) : [],
            'drillName' => $this->drill ? ($this->productNames([$this->drill['product_id']])[$this->drill['product_id']] ?? '') : '',
            'canValue' => $this->can('inventory.valuation.view'),
            'currency' => $this->currency(),
        ]);
    }
}

<?php

namespace App\Livewire\Inventory;

use App\Livewire\Concerns\PicksProducts;
use App\Livewire\WebPage;
use Livewire\Attributes\Title;

/** Book stock in without a purchase order: opening balances, returns, donations, other receipts. */
#[Title('Receive stock')]
class Receipts extends WebPage
{
    use PicksProducts;

    public const ROUTE = 'inventory.receipts';

    public array $form = ['warehouse_id' => '', 'supplier_id' => '', 'reason_code' => 'opening_balance', 'reference' => ''];

    public array $lines = [];

    public ?array $done = null;

    public function mount(): void
    {
        $this->addLine();
    }

    public function addLine(): void
    {
        $this->lines[] = ['product_id' => '', 'name' => null, 'levels' => [], 'batch_number' => '', 'expiry_date' => '', 'manufacturing_date' => '',
            'quantity' => '', 'package_level_code' => '', 'unit_cost' => '', 'quality_status' => 'released', 'bin_location_id' => ''];
    }

    public function removeLine(int $i): void
    {
        unset($this->lines[$i]);
        $this->lines = array_values($this->lines);
    }

    protected function productPicked(string $target, array $product): void
    {
        $i = (int) str_replace('line.', '', $target);
        $this->lines[$i] = array_merge($this->lines[$i], ['product_id' => $product['product_id'], 'name' => $product['name'], 'levels' => $product['levels'],
            'package_level_code' => array_key_first($product['levels']) ?? '', 'unit_cost' => $this->lines[$i]['unit_cost'] ?: (string) ($product['cost'] ?? '')]);
    }

    public function receive(): void
    {
        $lines = array_map(fn ($l) => array_filter(collect($l)->except(['name', 'levels'])->all(), fn ($v) => $v !== ''), $this->lines);
        $result = $this->submit('receipt', 'inventory/receipts', array_filter($this->form, fn ($v) => $v !== '') + ['lines' => $lines], 'Stock received.');
        if ($result !== null) {
            $this->done = ['lines' => count($lines), 'warehouse' => $this->warehouseOptions()[$this->form['warehouse_id']] ?? '', 'reference' => $this->form['reference']] + ($result['data'] ?? []);
            $this->lines = [];
            $this->addLine();
            $this->form['reference'] = '';
        }
    }

    public function render()
    {
        return view('livewire.inventory.receipts', [
            'warehouses' => $this->warehouseOptions(),
            'suppliers' => collect($this->read('suppliers', ['status' => 'active'])['data'] ?? [])->pluck('name', 'id'),
            'bins' => $this->form['warehouse_id'] ? collect($this->read("warehouses/{$this->form['warehouse_id']}/bins")['data'] ?? [])->pluck('code', 'id') : collect(),
        ]);
    }
}

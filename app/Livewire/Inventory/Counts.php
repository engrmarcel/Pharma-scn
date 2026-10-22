<?php

namespace App\Livewire\Inventory;

use App\Livewire\WebPage;
use Livewire\Attributes\Title;

/** Stock counts: open a count for a warehouse, enter what is on the shelf, review variances, post. */
#[Title('Stock counts')]
class Counts extends WebPage
{
    public const ROUTE = 'inventory.counts';

    public ?array $form = null;

    /** Count open on the count sheet. */
    public ?string $openId = null;

    /** batch_id => counted quantity typed on the sheet. */
    public array $counted = [];

    public function create(): void
    {
        $this->resetErrorBag();
        $this->form = ['warehouse_id' => '', 'notes' => ''];
    }

    public function save(): void
    {
        $result = $this->submit('form', 'stock-counts', array_filter($this->form), 'Count opened.');
        if ($result !== null) {
            $this->form = null;
            $this->page = 1;
            $this->open($result['data']['id']);
        }
    }

    public function open(string $id): void
    {
        $this->openId = $id;
        $count = collect($this->api()->get('stock-counts', ['page' => $this->page])['data'] ?? [])->firstWhere('id', $id);
        $this->counted = collect($count['lines'] ?? [])->mapWithKeys(fn ($l) => [$l['batch_id'] => (string) (float) $l['counted_quantity']])->all();
    }

    public function saveCounts(): void
    {
        $lines = collect($this->counted)->filter(fn ($v) => $v !== '' && $v !== null)->map(fn ($v, $batch) => ['batch_id' => $batch, 'counted_quantity' => $v])->values()->all();
        if ($lines === []) {
            $this->notify('Enter at least one counted quantity.', 'warning');

            return;
        }
        $this->submit('sheet', "stock-counts/{$this->openId}/lines", ['lines' => $lines], 'Counts saved.', 'put');
    }

    public function post(): void
    {
        $this->saveCounts();
        if ($this->getErrorBag()->isEmpty()) {
            $this->attempt(fn ($api) => $api->post("stock-counts/{$this->openId}/post"), 'Count posted: variances adjusted in the stock ledger.');
        }
    }

    public function render()
    {
        $result = $this->api()->get('stock-counts', ['page' => $this->page]);
        $rows = $result['data'] ?? [];
        $count = $this->openId ? collect($rows)->firstWhere('id', $this->openId) : null;
        $sheet = [];
        if ($count) {
            $balances = collect($this->read('inventory/balances', ['warehouse_id' => $count['warehouse_id'], 'stock_state' => 'available'])['data'] ?? []);
            $lines = collect($count['lines'] ?? [])->keyBy('batch_id');
            $sheet = $balances->map(fn ($b) => ['batch_id' => $b['batch_id'], 'product_id' => $b['product_id'], 'batch_number' => $b['batch_number'], 'expiry_date' => $b['expiry_date'],
                'system' => $b['quantity'], 'line' => $lines[$b['batch_id']] ?? null])->values()->all();
            foreach ($lines as $batchId => $line) {
                if (! $balances->contains('batch_id', $batchId)) {
                    $sheet[] = ['batch_id' => $batchId, 'product_id' => null, 'batch_number' => null, 'expiry_date' => null, 'system' => '0', 'line' => $line];
                }
            }
        }

        return view('livewire.inventory.counts', [
            'rows' => $rows,
            'meta' => $result,
            'count' => $count,
            'sheet' => $sheet,
            'warehouses' => $this->warehouseOptions(),
            'names' => $this->productNames(array_filter(array_column($sheet, 'product_id'))),
            'batches' => $this->batchLabels(array_column($sheet, 'batch_id')),
            'canCount' => $this->can('inventory.count'),
            'canPost' => $this->can('inventory.count') && $this->can('inventory.adjust'),
        ]);
    }
}

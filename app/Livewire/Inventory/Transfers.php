<?php

namespace App\Livewire\Inventory;

use App\Livewire\Concerns\PicksProducts;
use App\Livewire\WebPage;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

/** Transfers between the organization's warehouses: create, dispatch (FEFO picks batches), receive, cancel. */
#[Title('Stock transfers')]
class Transfers extends WebPage
{
    use PicksProducts;

    public const ROUTE = 'inventory.transfers';

    #[Url]
    public string $status = '';

    public ?array $form = null;

    public ?string $openId = null;

    /** line_id => quantity being received. */
    public array $receiving = [];

    protected array $filters = ['status'];

    public function create(): void
    {
        $this->resetErrorBag();
        $this->form = ['from_warehouse_id' => '', 'to_warehouse_id' => '', 'notes' => '', 'lines' => [['product_id' => '', 'name' => null, 'quantity' => '']]];
    }

    public function addLine(): void
    {
        $this->form['lines'][] = ['product_id' => '', 'name' => null, 'quantity' => ''];
    }

    public function removeLine(int $i): void
    {
        unset($this->form['lines'][$i]);
        $this->form['lines'] = array_values($this->form['lines']);
    }

    protected function productPicked(string $target, array $product): void
    {
        $i = (int) str_replace('line.', '', $target);
        $this->form['lines'][$i]['product_id'] = $product['product_id'];
        $this->form['lines'][$i]['name'] = $product['name'];
    }

    public function save(): void
    {
        $data = $this->form;
        $data['lines'] = array_map(fn ($l) => ['product_id' => $l['product_id'], 'quantity' => $l['quantity']], $data['lines']);
        $data['notes'] = $data['notes'] ?: null;
        $result = $this->submit('form', 'stock-transfers', $data, 'Transfer created as a draft.');
        if ($result !== null) {
            $this->form = null;
            $this->open($result['data']['id']);
        }
    }

    public function open(string $id): void
    {
        $this->openId = $id;
        $this->receiving = [];
    }

    public function act(string $action): void
    {
        $messages = ['dispatch' => 'Transfer dispatched: stock is in transit.', 'cancel' => 'Transfer cancelled.'];
        $this->attempt(fn ($api) => $api->post("stock-transfers/{$this->openId}/{$action}"), $messages[$action] ?? null);
    }

    public function receive(): void
    {
        $lines = collect($this->receiving)->filter(fn ($q) => $q !== '' && (float) $q > 0)->map(fn ($q, $id) => ['line_id' => (int) $id, 'quantity' => $q])->values()->all();
        if ($this->submit('receive', "stock-transfers/{$this->openId}/receive", ['lines' => $lines], 'Received into the destination warehouse.') !== null) {
            $this->receiving = [];
        }
    }

    public function render()
    {
        $result = $this->api()->get('stock-transfers', ['status' => $this->status, 'page' => $this->page]);
        $detail = $this->openId ? ($this->read("stock-transfers/{$this->openId}")['data'] ?? null) : null;
        if ($detail && $this->receiving === [] && in_array($detail['status'], ['dispatched', 'partially_received'], true)) {
            $this->receiving = collect($detail['lines'])->mapWithKeys(fn ($l) => [$l['id'] => (string) max(0, (float) $l['quantity'] - (float) $l['received_quantity'])])->all();
        }

        return view('livewire.inventory.transfers', [
            'rows' => $result['data'] ?? [],
            'meta' => $result,
            'detail' => $detail,
            'warehouses' => $this->warehouseOptions(),
            'names' => $this->productNames(collect($detail['lines'] ?? [])->pluck('product_id')->all()),
            'batches' => $this->batchLabels(collect($detail['lines'] ?? [])->pluck('batch_id')->filter()->all()),
            'canTransfer' => $this->can('inventory.transfer'),
        ]);
    }
}

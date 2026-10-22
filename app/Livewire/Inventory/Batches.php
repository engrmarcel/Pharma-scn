<?php

namespace App\Livewire\Inventory;

use App\Livewire\WebPage;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

/** Batches with expiry and quality status; quarantine, release or reject with a reason. */
#[Title('Batches')]
class Batches extends WebPage
{
    public const ROUTE = 'inventory.batches';

    #[Url]
    public string $batchNumber = '';

    #[Url]
    public string $quality = '';

    public string $expiringBefore = '';

    public ?string $openId = null;

    public ?array $decision = null;

    protected array $filters = ['batchNumber', 'quality', 'expiringBefore'];

    public function open(string $id): void
    {
        $this->openId = $id;
        $this->decision = null;
    }

    public function startDecision(string $action): void
    {
        $this->resetErrorBag();
        $this->decision = ['action' => $action, 'reason' => ''];
    }

    public function decide(): void
    {
        $action = $this->decision['action'];
        if ($this->submit('decision', "batches/{$this->openId}/{$action}", ['reason' => $this->decision['reason']], 'Batch '.match ($action) {
            'quarantine' => 'quarantined', 'release' => 'released', default => 'rejected'
        }.'.') !== null) {
            $this->decision = null;
        }
    }

    public function render()
    {
        $result = $this->api()->get('batches', ['batch_number' => trim($this->batchNumber), 'quality_status' => $this->quality, 'expiring_before' => $this->expiringBefore, 'page' => $this->page]);
        $rows = $result['data'] ?? [];
        $detail = $this->openId ? ($this->read("batches/{$this->openId}")['data'] ?? null) : null;

        return view('livewire.inventory.batches', [
            'rows' => $rows,
            'meta' => $result,
            'names' => $this->productNames(array_merge(array_column($rows, 'product_id'), $detail ? [$detail['product_id']] : [])),
            'detail' => $detail,
            'warehouses' => $this->warehouseOptions(),
            'canDecide' => $this->can('inventory.quality'),
            'canCost' => $this->can('inventory.valuation.view'),
        ]);
    }
}

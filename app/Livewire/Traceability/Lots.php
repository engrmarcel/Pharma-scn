<?php

namespace App\Livewire\Traceability;

use App\Livewire\Concerns\PicksProducts;
use App\Livewire\WebPage;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

/** Lot history: every event the organization recorded or took part in for one batch, in time order. */
#[Title('Lot history')]
class Lots extends WebPage
{
    use PicksProducts;

    public const ROUTE = 'trace.lots';

    #[Url(as: 'product')]
    public string $productId = '';

    public ?string $productName = null;

    #[Url(as: 'batch')]
    public string $batchNumber = '';

    protected function productPicked(string $target, array $product): void
    {
        $this->productId = $product['product_id'];
        $this->productName = $product['name'];
    }

    public function render()
    {
        $lot = $this->productId && trim($this->batchNumber) !== ''
            ? $this->read('trace/lots', ['product_id' => $this->productId, 'batch_number' => trim($this->batchNumber)], ['data' => null])['data'] : null;
        $this->productName ??= $this->productId ? ($this->productNames([$this->productId])[$this->productId] ?? null) : null;

        return view('livewire.traceability.lots', ['lot' => $lot]);
    }
}

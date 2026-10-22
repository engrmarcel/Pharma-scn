<?php

namespace App\Livewire\Catalogue;

use App\Livewire\Concerns\EditsProducts;
use App\Livewire\Concerns\PicksProducts;
use App\Livewire\WebPage;
use Livewire\Attributes\Title;

/** Proposals to add products to, or correct, the shared product master. Platform reviewers decide. */
#[Title('Catalogue change requests')]
class ChangeRequests extends WebPage
{
    use EditsProducts, PicksProducts;

    public const ROUTE = 'catalogue.change-requests';

    public ?array $viewing = null;

    public function proposeNew(): void
    {
        $this->resetErrorBag();
        $this->productBase = null;
        $this->product = $this->blankProduct();
    }

    /** Start a correction: choose the product, then edit its current values. */
    public function proposeCorrection(): void
    {
        $this->resetErrorBag();
        $this->product = null;
        $this->productBase = ['product_id' => null, 'version' => null, 'name' => null];
        $this->openPicker('correction');
    }

    protected function productPicked(string $target, array $product): void
    {
        $p = $this->api()->get("products/{$product['product_id']}")['data'];
        $this->productBase = ['product_id' => $p['id'], 'version' => $p['version'], 'name' => $p['display_name']];
        $this->product = $this->productFromApi($p);
    }

    public function send(): void
    {
        $body = ['payload' => $this->productPayload()];
        if ($this->productBase['product_id'] ?? null) {
            $body += ['product_id' => $this->productBase['product_id'], 'base_version' => $this->productBase['version']];
        }
        // The service validates the payload itself: its field errors arrive without the "payload." prefix.
        if ($this->submit('product', 'product-change-requests', $body, 'Proposal sent for review.') !== null) {
            $this->product = null;
            $this->productBase = null;
        }
    }

    public function show(array $row): void
    {
        $this->viewing = $row;
    }

    public function render()
    {
        $result = $this->api()->get('product-change-requests', ['page' => $this->page]);

        return view('livewire.catalogue.change-requests', [
            'rows' => $result['data'] ?? [],
            'meta' => $result['meta'] ?? [],
            'reference' => $this->product ? $this->catalogueReference() : [],
        ]);
    }
}

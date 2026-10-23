<?php

namespace App\Livewire\Admin;

use App\Domain\Organizations\Models\Organization;
use App\Livewire\Concerns\PicksProducts;
use App\Livewire\WebPage;
use App\Support\Web\ApiError;
use Livewire\Attributes\Title;

/** Network-wide lot history, independent verification of events, and an organization's chain integrity. */
#[Title('Network traceability')]
class Traceability extends WebPage
{
    use PicksProducts;

    public const ROUTE = 'admin.trace';

    public string $productId = '';

    public ?string $productName = null;

    public string $batchNumber = '';

    public ?array $verifying = null;

    public string $organization = '';

    public ?array $integrity = null;

    protected function productPicked(string $target, array $product): void
    {
        $this->productId = $product['product_id'];
        $this->productName = $product['name'];
    }

    public function startVerify(string $eventId): void
    {
        $this->resetErrorBag();
        $this->verifying = ['id' => $eventId, 'level' => 'independently_verified', 'method' => 'document_review', 'evidence' => ''];
    }

    public function verify(): void
    {
        $v = $this->verifying;
        if ($this->submit('verifying', "admin/trace/events/{$v['id']}/verifications", collect($v)->except('id')->all(), 'Verification recorded on the event.') !== null) {
            $this->verifying = null;
        }
    }

    public function checkIntegrity(): void
    {
        try {
            $this->integrity = $this->api()->get("admin/trace/integrity/{$this->organization}")['data'];
        } catch (ApiError $e) {
            $this->notify($e->getMessage(), 'error');
        }
    }

    public function render()
    {
        $lot = $this->productId && trim($this->batchNumber) !== '' ? $this->read('admin/trace/lots', ['product_id' => $this->productId, 'batch_number' => trim($this->batchNumber)], ['data' => null])['data'] : null;

        return view('livewire.admin.traceability', [
            'lot' => $lot,
            'organizations' => Organization::query()->orderBy('name')->pluck('name', 'id'),
            'canVerify' => $this->can('traceability.verify'),
        ]);
    }
}

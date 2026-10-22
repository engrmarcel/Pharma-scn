<?php

namespace App\Livewire\Integration;

use App\Livewire\Concerns\PicksProducts;
use App\Livewire\WebPage;
use Livewire\Attributes\Title;

/** External references: a partner system's product or warehouse code mapped to the platform's record. */
#[Title('External references')]
class References extends WebPage
{
    use PicksProducts;

    public const ROUTE = 'integration.references';

    public string $connector = '';

    public string $entity = '';

    public string $externalId = '';

    public ?array $form = null;

    protected array $filters = ['connector', 'entity', 'externalId'];

    public function create(): void
    {
        $this->resetErrorBag();
        $this->form = ['connector_id' => $this->connector, 'entity_type' => 'product', 'external_id' => '', 'internal_id' => '', 'internal_name' => null];
    }

    protected function productPicked(string $target, array $product): void
    {
        $this->form['internal_id'] = $product['product_id'];
        $this->form['internal_name'] = $product['name'];
    }

    public function save(): void
    {
        if ($this->submit('form', 'integration/external-references', collect($this->form)->except('internal_name')->all(), 'Reference saved.') !== null) {
            $this->form = null;
        }
    }

    public function remove(string $id): void
    {
        $this->attempt(fn ($api) => $api->delete("integration/external-references/{$id}"), 'Reference removed.');
    }

    public function render()
    {
        $result = $this->api()->get('integration/external-references', ['connector_id' => $this->connector, 'entity_type' => $this->entity, 'external_id' => trim($this->externalId), 'page' => $this->page]);
        $rows = $result['data'] ?? [];
        $warehouses = $this->warehouseOptions();

        return view('livewire.integration.references', [
            'rows' => $rows,
            'meta' => $result['meta'] ?? [],
            'connectors' => collect($this->read('integration/connectors')['data'] ?? [])->pluck('name', 'id'),
            'names' => $this->productNames(collect($rows)->where('entity_type', 'product')->pluck('internal_id')) + $warehouses,
            'warehouses' => $warehouses,
            'canManage' => $this->can('integrations.manage'),
        ]);
    }
}

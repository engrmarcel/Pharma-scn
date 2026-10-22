<?php

namespace App\Livewire\Integration;

use App\Livewire\Concerns\PicksProducts;
use App\Livewire\WebPage;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

/** Inbound messages: what arrived, how it was processed, and the review of quarantined or failed ones (retry, map, ignore). */
#[Title('Integration messages')]
class Messages extends WebPage
{
    use PicksProducts;

    public const ROUTE = 'integration.messages';

    #[Url]
    public string $status = '';

    #[Url]
    public string $connector = '';

    public string $entity = '';

    public string $messageId = '';

    #[Url(as: 'open')]
    public ?string $openId = null;

    public string $reason = '';

    public ?array $mapping = null;

    protected array $filters = ['status', 'connector', 'entity', 'messageId'];

    public function retry(): void
    {
        $this->attempt(fn ($api) => $api->post("integration/messages/{$this->openId}/retry"), 'Message queued for another attempt.');
    }

    public function ignore(): void
    {
        $this->submit('ignore', "integration/messages/{$this->openId}/ignore", ['reason' => $this->reason], 'Message ignored.');
    }

    public function startMap(): void
    {
        $this->resetErrorBag();
        $this->mapping = ['product_id' => '', 'product_name' => null, 'warehouse_id' => ''];
    }

    protected function productPicked(string $target, array $product): void
    {
        $this->mapping['product_id'] = $product['product_id'];
        $this->mapping['product_name'] = $product['name'];
    }

    public function map(): void
    {
        if ($this->submit('map', "integration/messages/{$this->openId}/map", array_filter(['product_id' => $this->mapping['product_id'], 'warehouse_id' => $this->mapping['warehouse_id']]),
            'Reference recorded and the message reprocessed.') !== null) {
            $this->mapping = null;
        }
    }

    public function render()
    {
        $result = $this->api()->get('integration/messages', ['status' => $this->status, 'connector_id' => $this->connector, 'entity_type' => $this->entity, 'message_id' => trim($this->messageId), 'page' => $this->page]);
        $connectors = collect($this->read('integration/connectors')['data'] ?? [])->pluck('name', 'id');

        return view('livewire.integration.messages', [
            'rows' => $result['data'] ?? [],
            'meta' => $result['meta'] ?? [],
            'detail' => $this->openId ? ($this->read("integration/messages/{$this->openId}")['data'] ?? null) : null,
            'connectors' => $connectors,
            'warehouses' => $this->warehouseOptions(),
            'canManage' => $this->can('integrations.manage'),
        ]);
    }
}

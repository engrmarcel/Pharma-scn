<?php

namespace App\Livewire\FieldForce;

use App\Livewire\Concerns\EditsDocumentLines;
use App\Livewire\WebPage;
use Illuminate\Support\Str;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

/** Orders taken in the field: drafts, submission to the ERP (a sales order), acceptance or rejection. */
#[Title('Field orders')]
class Orders extends WebPage
{
    use EditsDocumentLines;

    public const ROUTE = 'field-force.orders';

    #[Url]
    public string $status = '';

    #[Url(as: 'open')]
    public ?string $openId = null;

    public ?array $doc = null;

    protected array $filters = ['status'];

    public function create(): void
    {
        $this->resetErrorBag();
        $this->doc = ['customer_id' => '', 'warehouse_id' => '', 'requested_date' => '', 'notes' => '', 'submit' => true, 'lines' => [$this->blankLine()]];
    }

    public function save(): void
    {
        $d = $this->doc;
        $lines = array_map(fn ($l) => array_filter(['product_id' => $l['product_id'], 'package_level_code' => $l['package_level_code'], 'quantity' => $l['quantity'],
            'indicative_unit_price' => $l['unit_price']], fn ($v) => $v !== '' && $v !== null), $d['lines']);
        $result = $this->submit('doc', 'field-force/orders', array_filter(['client_reference' => 'web-'.Str::ulid(), 'customer_id' => $d['customer_id'], 'warehouse_id' => $d['warehouse_id'],
            'requested_date' => $d['requested_date'], 'notes' => $d['notes'], 'captured_at' => now()->toIso8601String(), 'submit' => (bool) $d['submit'], 'lines' => $lines],
            fn ($v) => $v !== '' && $v !== null), $d['submit'] ? 'Order captured and submitted.' : 'Order saved as a draft.');
        if ($result !== null) {
            $this->doc = null;
            $this->openId = $result['data']['id'];
        }
    }

    public function submitOrder(): void
    {
        $this->attempt(fn ($api) => $api->post("field-force/orders/{$this->openId}/submit"), 'Order submitted.');
    }

    public function cancelOrder(): void
    {
        $this->attempt(fn ($api) => $api->post("field-force/orders/{$this->openId}/cancel", ['reason' => 'Cancelled from the web']), 'Order cancelled.');
    }

    public function render()
    {
        $result = $this->api()->get('field-force/orders', ['status' => $this->status, 'page' => $this->page]);
        $rows = $result['data'] ?? [];
        $detail = $this->openId ? ($this->read("field-force/orders/{$this->openId}")['data'] ?? null) : null;

        return view('livewire.field-force.orders', [
            'rows' => $rows,
            'meta' => $result,
            'detail' => $detail,
            'customers' => collect($this->read('field-force/customers')['data'] ?? [])->pluck('name', 'id'),
            'people' => collect($this->read('members')['data'] ?? [])->mapWithKeys(fn ($m) => [$m['user']['id'] => $m['user']['name']]),
            'warehouses' => $this->warehouseOptions(),
            'names' => $this->productNames(collect($detail['lines'] ?? [])->pluck('product_id')),
            'canCapture' => $this->can('field_force.orders.capture'),
        ]);
    }
}

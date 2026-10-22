<?php

namespace App\Livewire\Logistics;

use App\Domain\Logistics\Models\TemperatureExcursion;
use App\Domain\Sales\Models\Delivery;
use App\Livewire\Concerns\PicksProducts;
use App\Livewire\WebPage;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\WithFileUploads;

/**
 * Shipments: plan from a delivery or transfer (or by hand), assign carrier, driver and vehicle,
 * dispatch, track, record temperatures and assess excursions, deliver with proof, or return.
 */
#[Title('Shipments')]
class Shipments extends WebPage
{
    use PicksProducts, WithFileUploads;

    public const ROUTE = 'logistics.shipments';

    #[Url]
    public string $status = '';

    public string $role = '';

    #[Url(as: 'open')]
    public ?string $openId = null;

    public ?array $form = null;

    /** Step being entered on the open shipment: assign, track, temperature, deliver, return, fail, cancel, assess. */
    public ?array $step = null;

    public $signature = null;

    protected array $filters = ['status', 'role'];

    public function create(): void
    {
        $this->resetErrorBag();
        $this->form = ['source_type' => 'sales_delivery', 'source_id' => '', 'origin_warehouse_id' => '', 'destination_name' => '', 'destination_address' => '',
            'planned_delivery_at' => '', 'notes' => '', 'items' => []];
    }

    public function addItem(): void
    {
        $this->form['items'][] = ['product_id' => '', 'name' => null, 'batch_number' => '', 'quantity' => ''];
    }

    protected function productPicked(string $target, array $product): void
    {
        $i = (int) str_replace('item.', '', $target);
        $this->form['items'][$i]['product_id'] = $product['product_id'];
        $this->form['items'][$i]['name'] = $product['name'];
    }

    public function save(): void
    {
        $f = $this->form;
        $data = array_filter(collect($f)->except('items')->all(), fn ($v) => $v !== '');
        if ($f['source_type'] === 'manual') {
            $data['items'] = array_map(fn ($i) => collect($i)->only(['product_id', 'batch_number', 'quantity'])->all(), $f['items']);
        }
        $result = $this->submit('form', 'logistics/shipments', $data, 'Shipment planned.');
        if ($result !== null) {
            $this->form = null;
            $this->openId = $result['data']['id'];
        }
    }

    public function startStep(string $type, array $shipment = []): void
    {
        $this->resetErrorBag();
        $this->signature = null;
        $items = collect($shipment['items'] ?? []);
        $this->step = match ($type) {
            'assign' => ['type' => $type, 'carrier_name' => $shipment['carrier']['name'] ?? '', 'driver_name' => $shipment['driver']['name'] ?? '', 'driver_phone' => $shipment['driver']['phone'] ?? '',
                'vehicle_id' => $shipment['vehicle']['id'] ?? '', 'temperature_logger_id' => $shipment['cold_chain']['logger_id'] ?? '', 'tracking_reference' => $shipment['tracking_reference'] ?? '',
                'planned_delivery_at' => isset($shipment['planned_delivery_at']) ? substr($shipment['planned_delivery_at'], 0, 16) : ''],
            'track' => ['type' => $type, 'event_type' => 'location_update', 'location_text' => '', 'notes' => ''],
            'temperature' => ['type' => $type, 'readings' => [['recorded_at' => now()->format('Y-m-d\TH:i'), 'temperature_c' => '']]],
            'deliver' => ['type' => $type, 'received_by_name' => '', 'received_by_role' => '', 'condition' => 'good', 'notes' => '',
                'items' => $items->mapWithKeys(fn ($i) => [$i['id'] => (string) (float) $i['quantity']])->all()],
            'return' => ['type' => $type, 'notes' => '', 'items' => $items->mapWithKeys(fn ($i) => [$i['id'] => (string) max(0, (float) $i['quantity'] - (float) ($i['delivered_quantity'] ?? 0))])->all()],
            default => ['type' => $type, 'reason' => '', 'assessment' => 'quarantine', 'notes' => '', 'excursion_id' => $shipment['excursion_id'] ?? null],
        };
    }

    public function addReading(): void
    {
        $this->step['readings'][] = ['recorded_at' => now()->format('Y-m-d\TH:i'), 'temperature_c' => ''];
    }

    public function dispatchShipment(): void
    {
        $this->attempt(fn ($api) => $api->post("logistics/shipments/{$this->openId}/dispatch"), 'Shipment dispatched.');
    }

    public function applyStep(): void
    {
        $s = $this->step;
        $id = $this->openId;
        $result = match ($s['type']) {
            'assign' => $this->submit('step', "logistics/shipments/{$id}/assign", array_filter(collect($s)->except('type')->all(), fn ($v) => $v !== ''), 'Assignment saved.'),
            'track' => $this->submit('step', "logistics/shipments/{$id}/events", array_filter(collect($s)->except('type')->all(), fn ($v) => $v !== ''), 'Tracking event recorded.'),
            'temperature' => $this->submit('step', "logistics/shipments/{$id}/temperatures", ['source' => 'manual', 'readings' => array_values(array_filter($s['readings'], fn ($r) => $r['temperature_c'] !== ''))], 'Readings recorded.'),
            'deliver' => $this->deliverWithProof($s),
            'return' => $this->submit('step', "logistics/shipments/{$id}/return", ['notes' => $s['notes'] ?: null,
                'items' => collect($s['items'])->map(fn ($q, $item) => ['shipment_item_id' => (int) $item, 'returned_quantity' => $q === '' ? '0' : $q])->values()->all()], 'Return recorded.'),
            'fail' => $this->submit('step', "logistics/shipments/{$id}/fail", ['reason' => $s['reason']], 'Delivery recorded as failed.'),
            'cancel' => $this->submit('step', "logistics/shipments/{$id}/cancel", ['reason' => $s['reason']], 'Shipment cancelled.'),
            'assess' => $this->submit('step', "logistics/shipments/{$id}/excursions/{$s['excursion_id']}/assess", ['assessment' => $s['assessment'], 'notes' => $s['notes']], 'Excursion assessed.'),
        };
        if ($result !== null) {
            $this->step = null;
        }
    }

    private function deliverWithProof(array $s): ?array
    {
        $body = array_filter(['received_by_name' => $s['received_by_name'], 'received_by_role' => $s['received_by_role'], 'condition' => $s['condition'], 'notes' => $s['notes']], fn ($v) => $v !== '');
        $items = collect($s['items'])->map(fn ($q, $item) => ['shipment_item_id' => (int) $item, 'delivered_quantity' => $q === '' ? '0' : $q])->values()->all();
        if ($this->signature) {
            return $this->attempt(fn ($api) => $api->upload("logistics/shipments/{$this->openId}/deliver", $body + ['items' => $items], ['signature' => $this->signature], 'step'),
                'Delivered with proof of delivery.');
        }

        return $this->submit('step', "logistics/shipments/{$this->openId}/deliver", $body + ['items' => $items], 'Delivered with proof of delivery.');
    }

    public function render()
    {
        $rows = $this->api()->get('logistics/shipments', ['status' => $this->status, 'role' => $this->role])['data'] ?? [];
        $detail = $this->openId ? ($this->read("logistics/shipments/{$this->openId}")['data'] ?? null) : null;
        $temps = $detail && $detail['cold_chain'] ? ($this->read("logistics/shipments/{$this->openId}/temperatures")['data'] ?? null) : null;

        return view('livewire.logistics.shipments', [
            'rows' => $rows,
            'detail' => $detail,
            'temps' => $temps,
            'warehouses' => $this->warehouseOptions(),
            'vehicles' => collect($this->read('logistics/vehicles')['data'] ?? [])->where('status', 'active')->mapWithKeys(fn ($v) => [$v['id'] => $v['registration'].($v['refrigerated'] ? ' (refrigerated)' : '')]),
            'deliveries' => match ($this->form['source_type'] ?? null) {
                'stock_transfer' => collect($this->read('stock-transfers', ['status' => 'dispatched'])['data'] ?? [])->pluck('number', 'id'),
                // Display lookup of the organization's recent deliveries (tenant-scoped model).
                'sales_delivery' => Delivery::query()->latest('delivered_at')->limit(50)->get(['id', 'number', 'delivered_at'])
                    ->mapWithKeys(fn ($d) => [$d->id => $d->number.' · '.$d->delivered_at?->format('Y-m-d')]),
                default => collect(),
            },
            'names' => $this->productNames(collect($detail['items'] ?? [])->pluck('product_id')),
            'assessments' => TemperatureExcursion::ASSESSMENTS,
            'can' => ['manage' => $this->can('logistics.manage'), 'deliver' => $this->can('logistics.deliver')],
        ]);
    }
}

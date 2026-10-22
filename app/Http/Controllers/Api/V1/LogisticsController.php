<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentEvent;
use App\Domain\Logistics\Models\TemperatureExcursion;
use App\Domain\Logistics\Models\Vehicle;
use App\Domain\Logistics\Services\ColdChainService;
use App\Domain\Logistics\Services\ShipmentService;
use App\Domain\Platform\Services\AuditLogger;
use App\Http\Controllers\Controller;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Logistics and delivery (spec §18). The shipper and an assigned carrier see the
 * same shipment; who may do what is enforced in ShipmentService.
 */
class LogisticsController extends Controller
{
    public function __construct(private readonly TenantContext $tenant, private readonly ShipmentService $shipments, private readonly ColdChainService $coldChain) {}

    // ---- Vehicles ----------------------------------------------------------------------------

    public function vehicles(): JsonResponse
    {
        Gate::authorize('logistics.view');

        return response()->json(['data' => Vehicle::query()->orderBy('registration')->get()]);
    }

    public function storeVehicle(Request $request, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('logistics.manage');
        $data = $request->validate([
            'registration' => ['required', 'string', 'max:32', Rule::unique('vehicles')->where('organization_id', $this->tenant->id())],
            'description' => ['nullable', 'string', 'max:255'],
            'refrigerated' => ['boolean'],
        ]);
        $vehicle = Vehicle::query()->create(['registration' => strtoupper($data['registration'])] + $data);
        $audit->record('vehicle.created', $vehicle, new: $vehicle->only('registration', 'refrigerated'));

        return response()->json(['data' => $vehicle], 201);
    }

    public function updateVehicle(Request $request, string $id, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('logistics.manage');
        $vehicle = Vehicle::query()->findOrFail($id);
        $data = $request->validate(['description' => ['nullable', 'string', 'max:255'], 'refrigerated' => ['boolean'], 'status' => [Rule::in(['active', 'inactive'])]]);
        $original = $vehicle->getAttributes();
        $vehicle->fill($data)->save();
        [$old, $new] = $audit->diff($vehicle, $original);
        $audit->record('vehicle.updated', $vehicle, $old, $new);

        return response()->json(['data' => $vehicle]);
    }

    // ---- Shipments ---------------------------------------------------------------------------

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('logistics.view');
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['planned', 'dispatched', 'in_transit', 'delivered', 'partially_delivered', 'failed', 'returned_to_origin', 'cancelled'])],
            'role' => ['nullable', Rule::in(['shipper', 'carrier'])],
            'mine' => ['boolean'],
        ]);
        $org = $this->tenant->id();
        $query = $this->shipments->visible()
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when(($filters['role'] ?? null) === 'shipper', fn ($q) => $q->where('organization_id', $org))
            ->when(($filters['role'] ?? null) === 'carrier', fn ($q) => $q->where('carrier_organization_id', $org))
            ->when($request->boolean('mine'), fn ($q) => $q->where('driver_user_id', $request->user()->id));

        return response()->json(['data' => $query->latest()->limit(200)->get()->map(fn (Shipment $s) => $this->summary($s))]);
    }

    public function show(string $id): JsonResponse
    {
        Gate::authorize('logistics.view');
        $shipment = $this->shipments->visible()->with('items', 'events', 'proof', 'excursions')->findOrFail($id);

        return response()->json(['data' => $this->detail($shipment)]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('logistics.manage');
        $data = $request->validate([
            'source_type' => ['required', Rule::in(['sales_delivery', 'stock_transfer', 'manual'])],
            'source_id' => ['required_unless:source_type,manual', 'nullable', 'ulid'],
            'origin_warehouse_id' => ['required_if:source_type,manual', 'nullable', 'ulid', Rule::exists('warehouses', 'id')->where('organization_id', $this->tenant->id())],
            'destination_name' => ['required_if:source_type,manual', 'nullable', 'string', 'max:255'],
            'destination_address' => ['nullable', 'string', 'max:255'],
            'items' => ['required_if:source_type,manual', 'array', 'max:200'],
            'items.*.product_id' => ['required', 'ulid'],
            'items.*.batch_number' => ['required', 'string', 'max:64'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'planned_delivery_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $shipment = $this->shipments->create($data, $request->user());

        return response()->json(['data' => $this->detail($shipment->load('events', 'proof', 'excursions'))], 201);
    }

    public function assign(Request $request, string $id): JsonResponse
    {
        $shipment = $this->shipments->visible()->findOrFail($id);
        $data = $request->validate([
            'carrier_organization_id' => ['nullable', 'ulid'],
            'carrier_name' => ['nullable', 'string', 'max:255'],
            'driver_user_id' => ['nullable', 'integer'],
            'driver_name' => ['nullable', 'string', 'max:255'],
            'driver_phone' => ['nullable', 'string', 'max:32'],
            'vehicle_id' => ['nullable', 'ulid'],
            'vehicle_registration' => ['nullable', 'string', 'max:32'],
            'temperature_logger_id' => ['nullable', 'string', 'max:64'],
            'tracking_reference' => ['nullable', 'string', 'max:64'],
            'planned_delivery_at' => ['nullable', 'date'],
        ]);

        return response()->json(['data' => $this->summary($this->shipments->assign($shipment, $data))]);
    }

    public function action(Request $request, string $id, string $action): JsonResponse
    {
        $shipment = $this->shipments->visible()->findOrFail($id);
        $result = match ($action) {
            'dispatch' => $this->shipments->dispatch($shipment, $request->validate(['occurred_at' => ['nullable', 'date']])['occurred_at'] ?? null),
            'fail' => $this->shipments->fail($shipment, ...array_values($request->validate(['reason' => ['required', 'string', 'max:2000'], 'occurred_at' => ['nullable', 'date']]) + ['occurred_at' => null])),
            'cancel' => $this->shipments->cancel($shipment, $request->validate(['reason' => ['required', 'string', 'max:2000']])['reason']),
        };

        return response()->json(['data' => $this->summary($result)]);
    }

    public function track(Request $request, string $id): JsonResponse
    {
        $shipment = $this->shipments->visible()->findOrFail($id);
        $data = $request->validate([
            'event_type' => ['required', Rule::in(['location_update', 'arrived', 'delivery_attempted', 'note'])],
            'occurred_at' => ['nullable', 'date'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'location_text' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return response()->json(['data' => $this->event($this->shipments->track($shipment, $data))], 201);
    }

    public function deliver(Request $request, string $id): JsonResponse
    {
        $shipment = $this->shipments->visible()->findOrFail($id);
        $data = $request->validate([
            'received_by_name' => ['required', 'string', 'max:255'],
            'received_by_role' => ['nullable', 'string', 'max:100'],
            'received_at' => ['nullable', 'date'],
            'condition' => ['required', Rule::in(['good', 'damaged', 'temperature_concern'])],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['array', 'max:200'],
            'items.*.shipment_item_id' => ['required', 'integer'],
            'items.*.delivered_quantity' => ['required', 'numeric', 'min:0'],
            'items.*.discrepancy_note' => ['nullable', 'string', 'max:255'],
            'signature' => ['nullable', 'file', 'mimes:png,jpg,jpeg', 'max:2048'],
            'photo' => ['nullable', 'file', 'mimes:png,jpg,jpeg', 'max:5120'],
        ]);
        $delivered = collect($data['items'] ?? [])->mapWithKeys(fn ($i) => [(int) $i['shipment_item_id'] => (string) $i['delivered_quantity']])->all();
        $result = $this->shipments->deliver($shipment, $data, $delivered, $request->file('signature'), $request->file('photo'), $request->user());
        foreach ($data['items'] ?? [] as $row) {
            if (! empty($row['discrepancy_note'])) {
                $result->items->firstWhere('id', (int) $row['shipment_item_id'])?->forceFill(['discrepancy_note' => $row['discrepancy_note']])->save();
            }
        }

        return response()->json(['data' => $this->detail($result->load('events', 'excursions'))]);
    }

    public function returnToOrigin(Request $request, string $id): JsonResponse
    {
        $shipment = $this->shipments->visible()->findOrFail($id);
        $data = $request->validate([
            'items' => ['array', 'max:200'],
            'items.*.shipment_item_id' => ['required', 'integer'],
            'items.*.returned_quantity' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $returned = collect($data['items'] ?? [])->mapWithKeys(fn ($i) => [(int) $i['shipment_item_id'] => (string) $i['returned_quantity']])->all();
        $result = $this->shipments->returnToOrigin($shipment, $returned, $data['notes'] ?? null);

        return response()->json(['data' => $this->detail($result->load('events', 'proof', 'excursions'))]);
    }

    // ---- Cold chain --------------------------------------------------------------------------

    public function temperatures(string $id): JsonResponse
    {
        Gate::authorize('logistics.view');
        $shipment = $this->shipments->visible()->findOrFail($id);

        return response()->json(['data' => [
            'required' => $shipment->requires_cold_chain ? ['min_c' => $shipment->temperature_min_c, 'max_c' => $shipment->temperature_max_c] : null,
            'readings' => $shipment->readings()->limit(5000)
                ->get(['recorded_at', 'temperature_c', 'source', 'device_id']),
            'excursions' => $shipment->excursions->map(fn ($e) => $this->excursion($e)),
        ]]);
    }

    public function recordTemperatures(Request $request, string $id): JsonResponse
    {
        Gate::authorize('logistics.deliver');
        $shipment = $this->shipments->visible()->findOrFail($id);
        $data = $request->validate([
            'source' => ['required', Rule::in(['logger', 'manual', 'iot'])],
            'readings' => ['required', 'array', 'min:1', 'max:2000'],
            'readings.*.recorded_at' => ['required', 'date', 'before_or_equal:'.now()->addMinutes(10)->toIso8601String()],
            'readings.*.temperature_c' => ['required', 'numeric', 'between:-80,80'],
            'readings.*.device_id' => ['nullable', 'string', 'max:64'],
        ]);

        return response()->json(['data' => $this->coldChain->ingest($shipment, $data['readings'], $data['source'], $request->user())], 201);
    }

    public function assess(Request $request, string $id, string $excursionId): JsonResponse
    {
        $shipment = $this->shipments->visible()->findOrFail($id);
        $excursion = TemperatureExcursion::query()->where('shipment_id', $shipment->id)->findOrFail($excursionId);
        $data = $request->validate(['assessment' => ['required', Rule::in(TemperatureExcursion::ASSESSMENTS)], 'notes' => ['required', 'string', 'max:2000']]);

        return response()->json(['data' => $this->excursion($this->coldChain->assess($excursion, $shipment, $data['assessment'], $data['notes'], $request->user()))]);
    }

    public function reconciliation(Request $request): JsonResponse
    {
        Gate::authorize('logistics.view');
        $data = $request->validate(['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from']]);

        return response()->json(['data' => $this->shipments->reconciliation($data['from'], $data['to'])]);
    }

    // ---- Presenters --------------------------------------------------------------------------

    private function summary(Shipment $s): array
    {
        return [
            'id' => $s->id, 'number' => $s->number, 'status' => $s->status, 'your_role' => $s->organization_id === $this->tenant->id() ? 'shipper' : 'carrier',
            'source_type' => $s->source_type, 'source_id' => $s->source_id, 'origin_warehouse_id' => $s->origin_warehouse_id,
            'destination' => ['organization_id' => $s->destination_organization_id, 'warehouse_id' => $s->destination_warehouse_id, 'name' => $s->destination_name, 'address' => $s->destination_address],
            'carrier' => ['organization_id' => $s->carrier_organization_id, 'name' => $s->carrier_name],
            'driver' => ['user_id' => $s->driver_user_id, 'name' => $s->driver_name, 'phone' => $s->driver_phone],
            'vehicle' => ['id' => $s->vehicle_id, 'registration' => $s->vehicle_registration],
            'cold_chain' => $s->requires_cold_chain ? ['min_c' => $s->temperature_min_c, 'max_c' => $s->temperature_max_c, 'logger_id' => $s->temperature_logger_id] : null,
            'tracking_reference' => $s->tracking_reference,
            'planned_delivery_at' => $s->planned_delivery_at?->toIso8601String(), 'dispatched_at' => $s->dispatched_at?->toIso8601String(),
            'delivered_at' => $s->delivered_at?->toIso8601String(), 'created_at' => $s->created_at?->toIso8601String(),
        ];
    }

    private function detail(Shipment $s): array
    {
        return $this->summary($s) + [
            'items' => $s->items->map(fn ($i) => ['id' => $i->id, 'product_id' => $i->product_id, 'batch_number' => $i->batch_number, 'expiry_date' => $i->expiry_date?->toDateString(),
                'quantity' => $i->quantity, 'delivered_quantity' => $i->delivered_quantity, 'returned_quantity' => $i->returned_quantity, 'discrepancy_note' => $i->discrepancy_note]),
            'events' => $s->events->map(fn ($e) => $this->event($e)),
            'proof_of_delivery' => $s->proof ? $s->proof->only('received_by_name', 'received_by_role', 'condition', 'signature_attachment_id', 'photo_attachment_id', 'cold_chain_unresolved', 'notes')
                + ['received_at' => $s->proof->received_at->toIso8601String(), 'latitude' => $s->proof->latitude, 'longitude' => $s->proof->longitude] : null,
            'excursions' => $s->excursions->map(fn ($e) => $this->excursion($e)),
            'next_steps' => $this->shipments->nextSteps($s),
        ];
    }

    private function event(ShipmentEvent $e): array
    {
        return ['id' => $e->id, 'event_type' => $e->event_type, 'occurred_at' => $e->occurred_at->toIso8601String(), 'recorded_by' => $e->organization_id === $this->tenant->id() ? 'you' : 'partner',
            'latitude' => $e->latitude, 'longitude' => $e->longitude, 'location_text' => $e->location_text, 'notes' => $e->notes];
    }

    private function excursion(TemperatureExcursion $e): array
    {
        return ['id' => $e->id, 'started_at' => $e->started_at->toIso8601String(), 'ended_at' => $e->ended_at?->toIso8601String(), 'minutes' => $e->minutes(),
            'lowest_c' => $e->lowest_c, 'highest_c' => $e->highest_c, 'readings' => $e->readings, 'status' => $e->status, 'assessment' => $e->assessment,
            'assessment_notes' => $e->assessment_notes, 'assessed_at' => $e->assessed_at?->toIso8601String()];
    }
}

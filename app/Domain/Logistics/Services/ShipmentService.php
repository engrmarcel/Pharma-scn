<?php

namespace App\Domain\Logistics\Services;

use App\Domain\Inventory\Models\Batch;
use App\Domain\Inventory\Services\DocumentNumberer;
use App\Domain\Logistics\Models\ProofOfDelivery;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentEvent;
use App\Domain\Logistics\Models\Vehicle;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Platform\Services\AttachmentService;
use App\Domain\Platform\Services\AuditLogger;
use App\Models\User;
use App\Support\Exceptions\BusinessRuleViolation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Logistics and delivery (spec §18): the physical execution of stock documents.
 *
 * Stock and money are moved by the documents (sales delivery, stock transfer,
 * marketplace receipt, credit note); a shipment records how the goods travel and
 * arrive: carrier, driver, vehicle, tracking events, proof of delivery, partial or
 * failed delivery, return to origin, and cold chain. Undelivered or returned
 * goods are corrected through the source document's own flow, so the books are
 * never touched twice; reconciliation shows what is still to be corrected.
 *
 * Shared by the shipper and, when assigned, a carrier on the platform: the carrier
 * records tracking, temperatures and proof of delivery; only the shipper plans,
 * assigns, cancels and assesses.
 */
class ShipmentService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly DocumentNumberer $numbers,
        private readonly AuditLogger $audit,
    ) {}

    public function visible(): Builder
    {
        $org = $this->tenant->id();

        return Shipment::query()->where(fn ($q) => $q->where('organization_id', $org)->orWhere('carrier_organization_id', $org));
    }

    public function create(array $data, User $actor): Shipment
    {
        return DB::transaction(function () use ($data, $actor) {
            [$header, $items] = match ($data['source_type']) {
                'sales_delivery' => $this->fromDelivery($data['source_id']),
                'stock_transfer' => $this->fromTransfer($data['source_id']),
                'manual' => $this->manual($data),
            };
            if ($data['source_type'] !== 'manual' && Shipment::query()->where('organization_id', $this->tenant->id())->where('source_type', $data['source_type'])
                ->where('source_id', $data['source_id'])->where('status', '!=', 'cancelled')->exists()) {
                throw new BusinessRuleViolation('shipment_exists', 'A shipment already exists for this document.', 409);
            }
            [$cold, $min, $max] = $this->coldChain(array_column($items, 'product_id'));

            $shipment = Shipment::query()->create($header + [
                'organization_id' => $this->tenant->id(), 'number' => $this->numbers->next('SHP'), 'source_type' => $data['source_type'],
                'source_id' => $data['source_id'] ?? null, 'requires_cold_chain' => $cold, 'temperature_min_c' => $min, 'temperature_max_c' => $max,
                'planned_delivery_at' => $data['planned_delivery_at'] ?? null, 'notes' => $data['notes'] ?? null, 'created_by' => $actor->id,
            ]);
            foreach ($items as $item) {
                $shipment->items()->create($item);
            }
            $this->event($shipment, 'created', notes: $cold ? "Cold chain {$min} °C to {$max} °C" : null);
            $this->audit->record('shipment.created', $shipment, new: ['number' => $shipment->number, 'source_type' => $shipment->source_type, 'cold_chain' => $cold]);

            return $shipment->load('items');
        });
    }

    public function assign(Shipment $shipment, array $data): Shipment
    {
        $this->assertShipper($shipment);
        if (! in_array($shipment->status, ['planned', 'dispatched'], true)) {
            throw new BusinessRuleViolation('shipment_not_assignable', "A {$shipment->status} shipment cannot be reassigned.", 409);
        }
        if (isset($data['carrier_organization_id'])) {
            $carrier = Organization::query()->findOrFail($data['carrier_organization_id']);
            if (! in_array($carrier->type?->value, ['logistics_provider', 'cold_chain_logistics_provider'], true) || ! $carrier->isActive()) {
                throw new BusinessRuleViolation('carrier_invalid', 'The carrier must be an active logistics provider on the platform.', 422);
            }
        }
        $carrierId = $data['carrier_organization_id'] ?? $shipment->carrier_organization_id;
        if (isset($data['driver_user_id']) && ! OrganizationMembership::query()->where('user_id', $data['driver_user_id'])->where('status', 'active')
            ->whereIn('organization_id', array_filter([$shipment->organization_id, $carrierId]))->exists()) {
            throw new BusinessRuleViolation('driver_invalid', 'The driver must be an active member of the shipper or the carrier.', 422);
        }
        if (isset($data['vehicle_id'])) {
            $vehicle = Vehicle::acrossTenants()->whereIn('organization_id', array_filter([$shipment->organization_id, $carrierId]))->find($data['vehicle_id'])
                ?? throw new BusinessRuleViolation('vehicle_invalid', 'The vehicle must belong to the shipper or the carrier.', 422);
            $data['vehicle_registration'] = $vehicle->registration;
        }
        $original = $shipment->getAttributes();
        $shipment->fill(array_intersect_key($data, array_flip(['carrier_organization_id', 'carrier_name', 'driver_user_id', 'driver_name', 'driver_phone',
            'vehicle_id', 'vehicle_registration', 'temperature_logger_id', 'tracking_reference', 'planned_delivery_at'])))->save();
        [$old, $new] = $this->audit->diff($shipment, $original);
        $this->audit->record('shipment.assigned', $shipment, $old, $new);

        return $shipment;
    }

    public function dispatch(Shipment $shipment, ?string $occurredAt): Shipment
    {
        $this->assertParticipant($shipment);
        if ($shipment->status !== 'planned') {
            throw new BusinessRuleViolation('shipment_not_planned', "A {$shipment->status} shipment cannot be dispatched.", 409);
        }
        if ($shipment->carrier_organization_id === null && blank($shipment->carrier_name) && $shipment->driver_user_id === null && blank($shipment->driver_name)) {
            throw new BusinessRuleViolation('shipment_unassigned', 'Assign a carrier or a driver before dispatch.', 422);
        }
        if ($shipment->requires_cold_chain) {
            $refrigerated = $shipment->vehicle_id ? (bool) Vehicle::acrossTenants()->whereKey($shipment->vehicle_id)->value('refrigerated') : false;
            if (! $refrigerated && blank($shipment->temperature_logger_id)) {
                throw new BusinessRuleViolation('cold_chain_equipment_required', "This shipment needs {$shipment->temperature_min_c} °C to {$shipment->temperature_max_c} °C: assign a refrigerated vehicle or a temperature logger before dispatch.", 422);
            }
        }
        $at = $this->time($occurredAt);
        $shipment->forceFill(['status' => 'dispatched', 'dispatched_at' => $at])->save();
        $this->event($shipment, 'dispatched', $at);

        return $shipment;
    }

    public function track(Shipment $shipment, array $data): ShipmentEvent
    {
        $this->assertParticipant($shipment);
        if (! in_array($shipment->status, ['dispatched', 'in_transit'], true)) {
            throw new BusinessRuleViolation('shipment_not_in_transit', 'Tracking events are recorded between dispatch and delivery.', 409);
        }
        if ($shipment->status === 'dispatched') {
            $shipment->forceFill(['status' => 'in_transit'])->save();
        }

        return $this->event($shipment, $data['event_type'], $this->time($data['occurred_at'] ?? null), $data['latitude'] ?? null, $data['longitude'] ?? null,
            $data['location_text'] ?? null, $data['notes'] ?? null);
    }

    /**
     * Proof of delivery. Quantities default to everything shipped; less than shipped on any line
     * makes the delivery partial, and the difference must come back (returnToOrigin) or be explained.
     *
     * @param  array<int, string>  $delivered  shipment_item_id => base quantity delivered
     */
    public function deliver(Shipment $shipment, array $data, array $delivered, ?UploadedFile $signature, ?UploadedFile $photo, User $actor): Shipment
    {
        $this->assertParticipant($shipment);

        return DB::transaction(function () use ($shipment, $data, $delivered, $signature, $photo, $actor) {
            $locked = Shipment::query()->lockForUpdate()->findOrFail($shipment->id);
            if (! in_array($locked->status, ['dispatched', 'in_transit'], true)) {
                throw new BusinessRuleViolation('shipment_not_in_transit', "A {$locked->status} shipment cannot be delivered.", 409);
            }
            $partial = false;
            foreach ($locked->items as $item) {
                $quantity = (string) ($delivered[$item->id] ?? $item->quantity);
                if (bccomp($quantity, '0', 4) < 0 || bccomp($quantity, (string) $item->quantity, 4) > 0) {
                    throw new BusinessRuleViolation('delivered_quantity_invalid', 'Delivered quantities must be between zero and the quantity shipped.', 422, ['shipment_item_id' => $item->id]);
                }
                $partial = $partial || bccomp($quantity, (string) $item->quantity, 4) < 0;
                $item->forceFill(['delivered_quantity' => $quantity])->save();
            }
            $at = $this->time($data['received_at'] ?? null);
            $proof = ProofOfDelivery::query()->create(['shipment_id' => $locked->id, 'received_by_name' => $data['received_by_name'],
                'received_by_role' => $data['received_by_role'] ?? null, 'received_at' => $at, 'latitude' => $data['latitude'] ?? null,
                'longitude' => $data['longitude'] ?? null, 'condition' => $data['condition'], 'notes' => $data['notes'] ?? null, 'recorded_by' => $actor->id,
                'cold_chain_unresolved' => $locked->excursions()->where('status', 'open')->exists()]);
            $files = app(AttachmentService::class);
            $proof->forceFill([
                'signature_attachment_id' => $signature ? $files->store($signature, $proof, 'proof_of_delivery_signature', $locked->organization_id, $actor)->id : null,
                'photo_attachment_id' => $photo ? $files->store($photo, $proof, 'proof_of_delivery_photo', $locked->organization_id, $actor)->id : null,
            ])->save();

            $locked->forceFill(['status' => $partial ? 'partially_delivered' : 'delivered', 'delivered_at' => $at])->save();
            $this->event($locked, $partial ? 'partially_delivered' : 'delivered', $at, $data['latitude'] ?? null, $data['longitude'] ?? null, null,
                trim("Received by {$data['received_by_name']}".($data['condition'] !== 'good' ? " ({$data['condition']})" : '')));
            $this->audit->record('shipment.delivered', $locked, new: ['partial' => $partial, 'condition' => $data['condition'], 'cold_chain_unresolved' => $proof->cold_chain_unresolved]);

            return $locked->load('items', 'proof');
        });
    }

    public function fail(Shipment $shipment, string $reason, ?string $occurredAt): Shipment
    {
        $this->assertParticipant($shipment);
        if (! in_array($shipment->status, ['dispatched', 'in_transit'], true)) {
            throw new BusinessRuleViolation('shipment_not_in_transit', "A {$shipment->status} shipment cannot fail delivery.", 409);
        }
        $at = $this->time($occurredAt);
        $shipment->forceFill(['status' => 'failed'])->save();
        $this->event($shipment, 'failed', $at, notes: $reason);
        $this->audit->record('shipment.failed', $shipment, new: ['reason' => $reason]);

        return $shipment;
    }

    /** @param array<int, string> $returned shipment_item_id => base quantity back at origin (default: everything not delivered) */
    public function returnToOrigin(Shipment $shipment, array $returned, ?string $notes): Shipment
    {
        $this->assertParticipant($shipment);

        return DB::transaction(function () use ($shipment, $returned, $notes) {
            $locked = Shipment::query()->lockForUpdate()->findOrFail($shipment->id);
            if (! in_array($locked->status, ['failed', 'partially_delivered', 'in_transit', 'dispatched'], true)) {
                throw new BusinessRuleViolation('shipment_not_returnable', "A {$locked->status} shipment cannot be returned to origin.", 409);
            }
            foreach ($locked->items as $item) {
                $open = bcsub((string) $item->quantity, (string) ($item->delivered_quantity ?? '0'), 4);
                $quantity = (string) ($returned[$item->id] ?? $open);
                if (bccomp($quantity, '0', 4) < 0 || bccomp($quantity, $open, 4) > 0) {
                    throw new BusinessRuleViolation('returned_quantity_invalid', 'Returned quantities cannot exceed what was not delivered.', 422, ['shipment_item_id' => $item->id]);
                }
                $item->forceFill(['returned_quantity' => $quantity])->save();
            }
            if ($locked->status !== 'partially_delivered') {
                $locked->forceFill(['status' => 'returned_to_origin'])->save();
            }
            $this->event($locked, 'returned_to_origin', now(), notes: $notes);
            $this->audit->record('shipment.returned_to_origin', $locked, new: ['returned' => $locked->items->pluck('returned_quantity', 'id')->map(fn ($q) => (string) $q)->all()]);

            return $locked->load('items');
        });
    }

    public function cancel(Shipment $shipment, string $reason): Shipment
    {
        $this->assertShipper($shipment);
        if ($shipment->status !== 'planned') {
            throw new BusinessRuleViolation('shipment_not_cancellable', 'Only planned shipments can be cancelled; a dispatched one fails or returns to origin.', 409);
        }
        $shipment->forceFill(['status' => 'cancelled'])->save();
        $this->event($shipment, 'cancelled', notes: $reason);

        return $shipment;
    }

    /** What needs correcting in the source document after a partial, failed or returned delivery. */
    public function nextSteps(Shipment $shipment): array
    {
        $undelivered = $shipment->items->reduce(fn ($c, $i) => bcadd($c, bcsub((string) $i->quantity, (string) ($i->delivered_quantity ?? '0'), 4), 4), '0');
        if (in_array($shipment->status, ['planned', 'dispatched', 'in_transit', 'delivered', 'cancelled'], true) || bccomp($undelivered, '0', 4) === 0) {
            return [];
        }

        return [match ($shipment->source_type) {
            'sales_delivery' => 'Record the goods that came back with a credit note on the sales order (restocked as quarantined), so stock, revenue and cost are corrected once.',
            'stock_transfer' => 'Receive the transfer for the quantity that actually arrived; the remainder stays in transit until it is resolved.',
            default => 'Post the returned or lost quantity through the appropriate stock document.',
        }];
    }

    /** Shipper's reconciliation for a period: shipped, delivered, returned, unaccounted; on-time; failures; cold chain. */
    public function reconciliation(string $from, string $to): array
    {
        $shipments = Shipment::query()->where('organization_id', $this->tenant->id())->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->with('items', 'excursions')->get();
        $sum = fn ($items, $field) => $items->reduce(fn ($c, $i) => bcadd($c, (string) ($i->{$field} ?? '0'), 4), '0');
        $closed = $shipments->whereIn('status', ['delivered', 'partially_delivered', 'failed', 'returned_to_origin']);
        $discrepancies = $closed->map(function ($s) use ($sum) {
            $unaccounted = bcsub(bcsub($sum($s->items, 'quantity'), $sum($s->items, 'delivered_quantity'), 4), $sum($s->items, 'returned_quantity'), 4);

            return bccomp($unaccounted, '0', 4) > 0 ? ['shipment_id' => $s->id, 'number' => $s->number, 'status' => $s->status, 'unaccounted' => $unaccounted] : null;
        })->filter()->values();
        $delivered = $shipments->whereIn('status', ['delivered', 'partially_delivered'])->whereNotNull('planned_delivery_at');

        return [
            'from' => $from, 'to' => $to,
            'shipments' => $shipments->countBy('status')->all(),
            'quantities' => ['shipped' => $sum($shipments->pluck('items')->flatten(), 'quantity'), 'delivered' => $sum($shipments->pluck('items')->flatten(), 'delivered_quantity'),
                'returned' => $sum($shipments->pluck('items')->flatten(), 'returned_quantity')],
            'discrepancies' => $discrepancies,
            'on_time_percent' => $delivered->isNotEmpty() ? round($delivered->filter(fn ($s) => $s->delivered_at->lte($s->planned_delivery_at))->count() / $delivered->count() * 100, 1) : null,
            'failed' => $shipments->where('status', 'failed')->map(fn ($s) => ['shipment_id' => $s->id, 'number' => $s->number])->values(),
            'cold_chain' => ['shipments' => $shipments->where('requires_cold_chain', true)->count(),
                'excursions' => $shipments->sum(fn ($s) => $s->excursions->count()),
                'unassessed' => $shipments->sum(fn ($s) => $s->excursions->where('status', 'open')->count())],
        ];
    }

    public function event(Shipment $shipment, string $type, ?Carbon $at = null, $lat = null, $lng = null, ?string $location = null, ?string $notes = null): ShipmentEvent
    {
        return ShipmentEvent::query()->create(['shipment_id' => $shipment->id, 'organization_id' => $this->tenant->id() ?? $shipment->organization_id,
            'event_type' => $type, 'occurred_at' => $at ?? now(), 'latitude' => $lat, 'longitude' => $lng, 'location_text' => $location, 'notes' => $notes,
            'actor_user_id' => Auth::id()]);
    }

    public function assertShipper(Shipment $shipment): void
    {
        Gate::authorize('logistics.manage');
        if ($shipment->organization_id !== $this->tenant->id()) {
            throw new BusinessRuleViolation('shipment_not_shipper', 'Only the shipper can do this.', 403);
        }
    }

    private function assertParticipant(Shipment $shipment): void
    {
        Gate::authorize('logistics.deliver');
        if (! in_array($this->tenant->id(), [$shipment->organization_id, $shipment->carrier_organization_id], true)) {
            throw new BusinessRuleViolation('shipment_not_found', 'Shipment not found.', 404);
        }
    }

    /** @return array{0: array, 1: list<array>} header and items */
    private function fromDelivery(string $id): array
    {
        $d = DB::table('deliveries as d')->join('sales_orders as o', 'o.id', '=', 'd.sales_order_id')->join('customers as c', 'c.id', '=', 'o.customer_id')
            ->where('d.organization_id', $this->tenant->id())->where('d.id', $id)
            ->first(['d.warehouse_id', 'c.name', 'c.address_line1', 'c.city', 'c.linked_organization_id']);
        if ($d === null) {
            throw new BusinessRuleViolation('source_not_found', 'Delivery not found.', 404);
        }
        $items = DB::table('delivery_lines as l')->join('batches as b', 'b.id', '=', 'l.batch_id')->where('l.delivery_id', $id)
            ->get(['b.product_id', 'b.batch_number', 'b.expiry_date', 'l.base_quantity'])
            ->map(fn ($r) => ['product_id' => $r->product_id, 'batch_number' => $r->batch_number, 'expiry_date' => $r->expiry_date, 'quantity' => $r->base_quantity])->all();

        return [['origin_warehouse_id' => $d->warehouse_id, 'destination_organization_id' => $d->linked_organization_id, 'destination_name' => $d->name,
            'destination_address' => trim(implode(', ', array_filter([$d->address_line1, $d->city])))], $items];
    }

    private function fromTransfer(string $id): array
    {
        $t = DB::table('stock_transfers as t')->join('warehouses as w', 'w.id', '=', 't.to_warehouse_id')
            ->where('t.organization_id', $this->tenant->id())->where('t.id', $id)->first(['t.from_warehouse_id', 't.to_warehouse_id', 't.status', 'w.name', 'w.address_line1', 'w.city']);
        if ($t === null) {
            throw new BusinessRuleViolation('source_not_found', 'Stock transfer not found.', 404);
        }
        if ($t->status !== 'in_transit') {
            throw new BusinessRuleViolation('transfer_not_dispatched', 'Ship a transfer once it has been dispatched (its stock is in transit).', 409);
        }
        $items = DB::table('stock_transfer_lines as l')->join('batches as b', 'b.id', '=', 'l.batch_id')->where('l.stock_transfer_id', $id)
            ->get(['b.product_id', 'b.batch_number', 'b.expiry_date', 'l.quantity'])
            ->map(fn ($r) => ['product_id' => $r->product_id, 'batch_number' => $r->batch_number, 'expiry_date' => $r->expiry_date, 'quantity' => $r->quantity])->all();

        return [['origin_warehouse_id' => $t->from_warehouse_id, 'destination_organization_id' => $this->tenant->id(), 'destination_warehouse_id' => $t->to_warehouse_id,
            'destination_name' => $t->name, 'destination_address' => trim(implode(', ', array_filter([$t->address_line1, $t->city])))], $items];
    }

    private function manual(array $data): array
    {
        $items = [];
        foreach ($data['items'] as $i => $row) {
            $batch = Batch::query()->where('product_id', $row['product_id'])->where('batch_number', strtoupper($row['batch_number']))->first()
                ?? throw new BusinessRuleViolation('batch_not_found', 'Each item must be a batch the organization holds.', 422, ['item' => $i]);
            $items[] = ['product_id' => $batch->product_id, 'batch_number' => $batch->batch_number, 'expiry_date' => $batch->expiry_date?->toDateString(), 'quantity' => $row['quantity']];
        }

        return [['origin_warehouse_id' => $data['origin_warehouse_id'], 'destination_name' => $data['destination_name'], 'destination_address' => $data['destination_address'] ?? null], $items];
    }

    /**
     * Cold chain only where a product needs it (spec §18: not every shipment does). The required
     * range is the strictest across the items; incompatible ranges cannot travel together.
     *
     * @return array{0: bool, 1: ?string, 2: ?string}
     */
    private function coldChain(array $productIds): array
    {
        $products = DB::table('products')->whereIn('id', array_unique($productIds))->get(['requires_cold_chain', 'temperature_min_c', 'temperature_max_c']);
        $cold = $products->contains(fn ($p) => (bool) $p->requires_cold_chain);
        if (! $cold) {
            return [false, null, null];
        }
        $min = $products->whereNotNull('temperature_min_c')->max('temperature_min_c');
        $max = $products->whereNotNull('temperature_max_c')->min('temperature_max_c');
        if ($min !== null && $max !== null && (float) $min > (float) $max) {
            throw new BusinessRuleViolation('cold_chain_ranges_incompatible', 'These products need temperature ranges that do not overlap; ship them separately.', 422);
        }

        return [true, $min ?? '2.00', $max ?? '8.00'];
    }

    private function time(?string $value): Carbon
    {
        $at = $value ? Carbon::parse($value) : now();
        if ($at->isAfter(now()->addMinutes(10))) {
            throw new BusinessRuleViolation('occurred_in_future', 'The time is in the future.', 422);
        }

        return $at;
    }
}

<?php

namespace App\Domain\FieldForce\Services;

use App\Domain\FieldForce\Models\FieldOrder;
use App\Domain\FieldForce\Models\FieldVisit;
use App\Domain\FieldForce\Models\Territory;
use App\Domain\Inventory\Services\DocumentNumberer;
use App\Domain\Organizations\Models\Warehouse;
use App\Domain\Platform\Services\AuditLogger;
use App\Domain\Sales\Models\SalesOrder;
use App\Domain\Sales\Services\SalesOrderService;
use App\Support\Exceptions\BusinessRuleViolation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Mobile orders (spec §12.4). A captured order is only a request:
 *
 *   draft → submit → accepted  (the ERP created a sales order and confirmed it,
 *                               or holds it for approval; stock is reserved only
 *                               when that sales order is confirmed)
 *                  → rejected  (validation failed; reasons recorded) → edit → draft
 *   draft | rejected → cancelled
 *
 * Prices on the device are indicative; the sales order is priced by the ERP.
 */
class FieldOrderService
{
    public function __construct(
        private readonly FieldForceScope $scope,
        private readonly LocationEvidence $clock,
        private readonly SalesOrderService $salesOrders,
        private readonly DocumentNumberer $numbers,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{order: FieldOrder, replayed: bool}
     */
    public function capture(array $data): array
    {
        $result = DB::transaction(function () use ($data) {
            $existing = FieldOrder::query()->where('client_reference', $data['client_reference'])->first();
            if ($existing !== null) {
                if ($existing->representative_id !== Auth::id()) {
                    throw new BusinessRuleViolation('client_reference_conflict', 'This client reference is already used by another record.', 409);
                }

                return ['order' => $existing, 'replayed' => true];
            }

            $customer = $this->scope->visibleCustomer($data['customer_id']);
            if (isset($data['field_visit_id'])) {
                $visit = FieldVisit::query()->where('representative_id', Auth::id())->find($data['field_visit_id']);
                if ($visit === null || $visit->customer_id !== $customer->id) {
                    throw new BusinessRuleViolation('visit_mismatch', 'The visit must be your own visit to the same customer.', 422);
                }
            }

            $order = FieldOrder::query()->create([
                'number' => $this->numbers->next('FO'),
                'client_reference' => $data['client_reference'],
                'representative_id' => Auth::id(),
                'customer_id' => $customer->id,
                'field_visit_id' => $data['field_visit_id'] ?? null,
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'captured_at' => $this->clock->occurredAt($data['captured_at']),
                'captured_offline' => (bool) ($data['captured_offline'] ?? false),
                'requested_date' => $data['requested_date'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);
            $this->writeLines($order, $data['lines']);
            $this->audit->record('field_order.captured', $order, new: ['number' => $order->number, 'customer_id' => $customer->id, 'lines' => count($data['lines'])]);

            return ['order' => $order, 'replayed' => false];
        });

        if (! $result['replayed'] && ($data['submit'] ?? false)) {
            $result['order'] = $this->submit($result['order']);
        }

        return $result;
    }

    public function update(FieldOrder $order, array $data): FieldOrder
    {
        return DB::transaction(function () use ($order, $data) {
            $locked = $this->own($order);
            if (! in_array($locked->status, ['draft', 'rejected'], true)) {
                throw new BusinessRuleViolation('field_order_locked', "A {$locked->status} order cannot be edited.", 409);
            }
            $locked->fill(array_intersect_key($data, array_flip(['warehouse_id', 'requested_date', 'notes'])) + ['status' => 'draft', 'rejection_reasons' => null]);
            $locked->save();
            if (isset($data['lines'])) {
                $locked->lines()->delete();
                $this->writeLines($locked, $data['lines']);
            }

            return $locked->load('lines');
        });
    }

    /** Server-side validation: on success the ERP owns the order from here on. */
    public function submit(FieldOrder $order): FieldOrder
    {
        return DB::transaction(function () use ($order) {
            $locked = $this->own($order);
            if ($locked->status === 'accepted') {
                return $locked->load('lines'); // replay of an already-accepted submission
            }
            if (! in_array($locked->status, ['draft', 'rejected'], true)) {
                throw new BusinessRuleViolation('field_order_state_invalid', "A {$locked->status} order cannot be submitted.", 409);
            }
            $locked->forceFill(['status' => 'submitted', 'submitted_at' => now(), 'rejection_reasons' => null])->save();

            try {
                // Savepoint: a failed validation leaves no sales order and no reservations behind.
                $salesOrder = DB::transaction(function () use ($locked) {
                    $customer = $this->scope->visibleCustomer($locked->customer_id);
                    $warehouse = $this->fulfilmentWarehouse($locked, $customer->territory_id);
                    $sales = $this->salesOrders->createOrder($customer, $warehouse,
                        $locked->lines()->get()->map(fn ($l) => array_filter([
                            'product_id' => $l->product_id, 'package_level_code' => $l->package_level_code, 'quantity' => (string) $l->quantity,
                        ], fn ($v) => $v !== null))->all(),
                        $locked->requested_date?->toDateString(), trim("Mobile order {$locked->number}. ".($locked->notes ?? '')));

                    return $this->salesOrders->confirm($sales);
                });
            } catch (BusinessRuleViolation $e) {
                $locked->forceFill(['status' => 'rejected', 'validated_at' => now(),
                    'rejection_reasons' => [['code' => $e->errorCode, 'message' => $e->getMessage(), 'details' => $e->details]]])->save();
                $this->audit->record('field_order.rejected', $locked, new: ['code' => $e->errorCode]);

                return $locked->load('lines');
            }

            $locked->forceFill(['status' => 'accepted', 'validated_at' => now(), 'sales_order_id' => $salesOrder->id, 'warehouse_id' => $salesOrder->warehouse_id])->save();
            $this->audit->record('field_order.accepted', $locked, new: ['sales_order' => $salesOrder->number, 'sales_order_status' => $salesOrder->status]);

            return $locked->load('lines');
        });
    }

    public function cancel(FieldOrder $order, string $reason): FieldOrder
    {
        return DB::transaction(function () use ($order, $reason) {
            $locked = $this->own($order);
            if (! in_array($locked->status, ['draft', 'rejected'], true)) {
                throw new BusinessRuleViolation('field_order_state_invalid', "A {$locked->status} order cannot be cancelled here; cancel the sales order instead.", 409);
            }
            $locked->forceFill(['status' => 'cancelled'])->save();
            $this->audit->record('field_order.cancelled', $locked, new: ['reason' => $reason]);

            return $locked;
        });
    }

    /** Status as the representative should see it: captured vs. accepted by the ERP, and whether stock is reserved. */
    public function present(FieldOrder $order): array
    {
        $sales = $order->sales_order_id ? SalesOrder::query()->find($order->sales_order_id, ['id', 'number', 'status', 'total', 'currency_code']) : null;

        return $order->loadMissing('lines')->toArray() + [
            'accepted_by_erp' => $order->status === 'accepted',
            'sales_order' => $sales?->only(['id', 'number', 'status', 'total', 'currency_code']),
            'stock_reserved' => $sales !== null && in_array($sales->status, ['confirmed', 'partially_delivered', 'delivered', 'closed'], true),
        ];
    }

    private function fulfilmentWarehouse(FieldOrder $order, ?string $territoryId): Warehouse
    {
        $id = $order->warehouse_id ?? ($territoryId ? Territory::query()->whereKey($territoryId)->value('warehouse_id') : null);
        if ($id === null) {
            throw new BusinessRuleViolation('fulfilment_warehouse_missing', "No fulfilment warehouse: choose one or set the customer's territory warehouse.", 422);
        }

        return Warehouse::query()->findOrFail($id);
    }

    private function own(FieldOrder $order): FieldOrder
    {
        $locked = FieldOrder::query()->lockForUpdate()->findOrFail($order->id);
        if ($locked->representative_id !== Auth::id()) {
            throw new BusinessRuleViolation('field_order_not_yours', 'Only the representative who captured this order can change it.', 403);
        }

        return $locked;
    }

    private function writeLines(FieldOrder $order, array $lines): void
    {
        foreach ($lines as $l) {
            $order->lines()->create([
                'product_id' => $l['product_id'], 'package_level_code' => $l['package_level_code'] ?? null,
                'quantity' => $l['quantity'], 'indicative_unit_price' => $l['indicative_unit_price'] ?? null, 'notes' => $l['notes'] ?? null,
            ]);
        }
    }
}

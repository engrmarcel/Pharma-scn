<?php

namespace App\Domain\Procurement\Services;

use App\Domain\Catalogue\Services\UnitConverter;
use App\Domain\Inventory\Services\DocumentNumberer;
use App\Domain\Organizations\Models\Warehouse;
use App\Domain\Parties\Models\Supplier;
use App\Domain\Platform\Services\AuditLogger;
use App\Domain\Procurement\Events\PurchaseOrderApproved;
use App\Domain\Procurement\Events\PurchaseOrderCreated;
use App\Domain\Procurement\Models\PurchaseOrder;
use App\Domain\Procurement\Models\PurchaseRequisition;
use App\Domain\Procurement\Models\SupplierPriceListItem;
use App\Models\User;
use App\Support\Exceptions\BusinessRuleViolation;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Requisitions and purchase orders.
 *
 *   requisition: draft → submitted → approved | rejected ; approved → converted (to POs)
 *   PO: draft → (submit) → pending_approval | approved → sent → partially_received → received → closed
 *       draft/pending_approval/approved → cancelled ; pending_approval → rejected (back to draft on edit)
 */
class PurchaseOrderService
{
    public function __construct(
        private readonly UnitConverter $units,
        private readonly DocumentNumberer $numbers,
        private readonly ProcurementPolicy $policy,
        private readonly AuditLogger $audit,
    ) {}

    // ---- Requisitions -------------------------------------------------------

    /** @param list<array{product_id: string, quantity: string, package_level_code?: ?string, suggested_supplier_id?: ?string, notes?: ?string}> $lines */
    public function createRequisition(Warehouse $warehouse, array $lines, ?string $neededBy = null, ?string $notes = null): PurchaseRequisition
    {
        return DB::transaction(function () use ($warehouse, $lines, $neededBy, $notes) {
            $requisition = PurchaseRequisition::query()->create([
                'number' => $this->numbers->next('PRQ'),
                'warehouse_id' => $warehouse->id,
                'status' => 'draft',
                'needed_by' => $neededBy,
                'notes' => $notes,
                'requested_by' => Auth::id(),
            ]);

            foreach ($lines as $line) {
                $level = $this->units->level($line['product_id'], $line['package_level_code'] ?? null);
                $requisition->lines()->create([
                    'product_id' => $line['product_id'],
                    'package_level_code' => $level->code,
                    'quantity' => $line['quantity'],
                    'base_quantity' => bcmul((string) $line['quantity'], (string) $level->base_unit_quantity, 4),
                    'suggested_supplier_id' => $line['suggested_supplier_id'] ?? null,
                    'notes' => $line['notes'] ?? null,
                ]);
            }
            $this->audit->record('purchase_requisition.created', $requisition, new: ['number' => $requisition->number, 'lines' => count($lines)]);

            return $requisition->load('lines');
        });
    }

    public function submitRequisition(PurchaseRequisition $requisition): PurchaseRequisition
    {
        return $this->transition($requisition, ['draft'], 'submitted', 'purchase_requisition.submitted');
    }

    public function decideRequisition(PurchaseRequisition $requisition, bool $approve, User $approver, ?string $notes): PurchaseRequisition
    {
        $this->policy->assertMayApprove($requisition->requested_by, $approver);
        if (! $approve && blank($notes)) {
            throw new BusinessRuleViolation('decision_notes_required', 'Give a reason when rejecting.');
        }

        return $this->transition($requisition, ['submitted'], $approve ? 'approved' : 'rejected', 'purchase_requisition.'.($approve ? 'approved' : 'rejected'), [
            'decided_by' => $approver->id, 'decided_at' => now(), 'decision_notes' => $notes,
        ]);
    }

    /**
     * Turn an approved requisition into draft POs, one per supplier.
     *
     * @return Collection<int, PurchaseOrder>
     */
    public function convertRequisition(PurchaseRequisition $requisition, ?string $defaultSupplierId): Collection
    {
        return DB::transaction(function () use ($requisition, $defaultSupplierId) {
            $locked = PurchaseRequisition::query()->lockForUpdate()->findOrFail($requisition->id);
            if ($locked->status !== 'approved') {
                throw new BusinessRuleViolation('requisition_state_invalid', "Requisition is {$locked->status}; only approved requisitions can be converted.", 409);
            }

            $groups = $locked->lines()->get()->groupBy(function ($line) use ($defaultSupplierId) {
                return $line->suggested_supplier_id ?? $defaultSupplierId
                    ?? throw new BusinessRuleViolation('supplier_required', 'Choose a supplier for lines without a suggested supplier.', 422);
            });

            $orders = $groups->map(fn ($lines, $supplierId) => $this->createOrder(
                Supplier::query()->findOrFail($supplierId),
                Warehouse::query()->findOrFail($locked->warehouse_id),
                $lines->map(fn ($l) => ['product_id' => $l->product_id, 'package_level_code' => $l->package_level_code, 'quantity' => (string) $l->quantity])->all(),
                requisitionId: $locked->id,
            ))->values();

            $locked->forceFill(['status' => 'converted'])->save();
            $this->audit->record('purchase_requisition.converted', $locked, new: ['purchase_orders' => $orders->pluck('number')->all()]);

            return $orders;
        });
    }

    // ---- Purchase orders ----------------------------------------------------

    /**
     * @param  list<array{product_id: string, quantity: string, package_level_code?: ?string, unit_price?: ?string, tax_rate?: ?string}>  $lines
     */
    public function createOrder(Supplier $supplier, Warehouse $warehouse, array $lines, ?string $expectedDate = null, ?string $notes = null, ?string $requisitionId = null): PurchaseOrder
    {
        if (! $supplier->isActive()) {
            throw new BusinessRuleViolation('supplier_not_active', "Supplier {$supplier->code} is {$supplier->status}.", 409);
        }

        return DB::transaction(function () use ($supplier, $warehouse, $lines, $expectedDate, $notes, $requisitionId) {
            $order = PurchaseOrder::query()->create([
                'number' => $this->numbers->next('PO'),
                'supplier_id' => $supplier->id,
                'warehouse_id' => $warehouse->id,
                'purchase_requisition_id' => $requisitionId,
                'status' => 'draft',
                'currency_code' => $supplier->currency_code,
                'expected_date' => $expectedDate ?? ($supplier->lead_time_days !== null ? today()->addDays($supplier->lead_time_days)->toDateString() : null),
                'payment_terms_days' => $supplier->payment_terms_days,
                'notes' => $notes,
                'created_by' => Auth::id(),
            ]);
            $this->replaceLines($order, $lines);
            $this->audit->record('purchase_order.created', $order, new: ['number' => $order->number, 'supplier_id' => $supplier->id, 'total' => (string) $order->total]);
            event(new PurchaseOrderCreated($order->id, $order->organization_id));

            return $order->load('lines');
        });
    }

    public function updateOrder(PurchaseOrder $order, array $data): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $data) {
            $locked = PurchaseOrder::query()->lockForUpdate()->findOrFail($order->id);
            if (! in_array($locked->status, ['draft', 'rejected'], true)) {
                throw new BusinessRuleViolation('purchase_order_locked', "A {$locked->status} purchase order cannot be edited.", 409);
            }

            $locked->fill(array_intersect_key($data, array_flip(['expected_date', 'notes'])));
            $locked->status = 'draft';
            $locked->save();
            if (isset($data['lines'])) {
                $this->replaceLines($locked, $data['lines']);
            }
            $this->audit->record('purchase_order.updated', $locked, new: array_diff_key($data, ['lines' => 1]) + ['total' => (string) $locked->total]);

            return $locked->load('lines');
        });
    }

    public function submitOrder(PurchaseOrder $order, User $user): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $user) {
            $locked = PurchaseOrder::query()->lockForUpdate()->findOrFail($order->id);
            if ($locked->status !== 'draft') {
                throw new BusinessRuleViolation('purchase_order_state_invalid', "Purchase order is {$locked->status}.", 409);
            }
            if ($locked->lines()->count() === 0) {
                throw new BusinessRuleViolation('purchase_order_empty', 'Add at least one line.', 422);
            }

            if ($this->policy->needsApproval((string) $locked->total)) {
                $locked->forceFill(['status' => 'pending_approval'])->save();
                $this->audit->record('purchase_order.submitted', $locked, new: ['status' => 'pending_approval', 'total' => (string) $locked->total]);
            } else {
                $this->approve($locked, $user, auto: true);
            }

            return $locked->fresh('lines');
        });
    }

    public function decideOrder(PurchaseOrder $order, bool $approve, User $approver, ?string $reason = null): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $approve, $approver, $reason) {
            $locked = PurchaseOrder::query()->lockForUpdate()->findOrFail($order->id);
            if ($locked->status !== 'pending_approval') {
                throw new BusinessRuleViolation('purchase_order_state_invalid', "Purchase order is {$locked->status}.", 409);
            }
            $this->policy->assertMayApprove($locked->created_by, $approver);

            if ($approve) {
                $this->approve($locked, $approver);
            } else {
                if (blank($reason)) {
                    throw new BusinessRuleViolation('decision_notes_required', 'Give a reason when rejecting.');
                }
                $locked->forceFill(['status' => 'rejected', 'status_reason' => $reason])->save();
                $this->audit->record('purchase_order.rejected', $locked, new: ['reason' => $reason]);
            }

            return $locked->fresh('lines');
        });
    }

    public function markSent(PurchaseOrder $order): PurchaseOrder
    {
        return $this->transition($order, ['approved'], 'sent', 'purchase_order.sent', ['sent_at' => now()]);
    }

    public function cancel(PurchaseOrder $order, string $reason): PurchaseOrder
    {
        if ($order->lines()->where('received_base_quantity', '>', 0)->exists()) {
            throw new BusinessRuleViolation('purchase_order_has_receipts', 'Stock has been received against this order; close it instead.', 409);
        }

        return $this->transition($order, ['draft', 'pending_approval', 'approved', 'sent', 'rejected'], 'cancelled', 'purchase_order.cancelled', ['status_reason' => $reason]);
    }

    /** Close an order that will not be delivered in full; outstanding quantity is abandoned. */
    public function close(PurchaseOrder $order, string $reason): PurchaseOrder
    {
        return $this->transition($order, ['partially_received', 'received', 'sent', 'approved'], 'closed', 'purchase_order.closed', ['status_reason' => $reason]);
    }

    private function approve(PurchaseOrder $order, User $approver, bool $auto = false): void
    {
        $order->forceFill(['status' => 'approved', 'approved_by' => $approver->id, 'approved_at' => now(), 'order_date' => $order->order_date ?? today()])->save();
        $this->audit->record('purchase_order.approved', $order, new: ['auto_approved_below_threshold' => $auto, 'total' => (string) $order->total]);
        event(new PurchaseOrderApproved($order->id, $order->organization_id));
    }

    private function replaceLines(PurchaseOrder $order, array $lines): void
    {
        if ($lines === []) {
            throw new BusinessRuleViolation('purchase_order_empty', 'Add at least one line.', 422);
        }

        $order->lines()->delete();
        $subtotal = '0';
        $tax = '0';

        foreach ($lines as $i => $line) {
            $level = $this->units->level($line['product_id'], $line['package_level_code'] ?? null);
            $qty = (string) $line['quantity'];
            $price = isset($line['unit_price']) ? (string) $line['unit_price'] : $this->priceFromList($order, $line['product_id'], $level->code, $qty, $i);
            [$lineSubtotal, $lineTax] = Money::line($qty, $price, (string) ($line['tax_rate'] ?? '0'));

            $order->lines()->create([
                'product_id' => $line['product_id'],
                'package_level_code' => $level->code,
                'quantity' => $qty,
                'base_quantity' => bcmul($qty, (string) $level->base_unit_quantity, 4),
                'unit_price' => $price,
                'tax_rate' => $line['tax_rate'] ?? '0',
                'line_subtotal' => $lineSubtotal,
                'line_tax' => $lineTax,
            ]);
            $subtotal = Money::add($subtotal, $lineSubtotal);
            $tax = Money::add($tax, $lineTax);
        }

        $order->forceFill(['subtotal' => $subtotal, 'tax_total' => $tax, 'total' => Money::add($subtotal, $tax)])->save();
    }

    private function priceFromList(PurchaseOrder $order, string $productId, string $levelCode, string $qty, int $line): string
    {
        $today = today()->toDateString();
        $price = SupplierPriceListItem::query()
            ->where('supplier_id', $order->supplier_id)->where('product_id', $productId)->where('package_level_code', $levelCode)
            ->where('currency_code', $order->currency_code)->where('minimum_quantity', '<=', $qty)
            ->where(fn ($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $today))
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $today))
            ->orderByDesc('minimum_quantity')
            ->value('unit_price');

        return $price !== null ? (string) $price
            : throw new BusinessRuleViolation('price_required', 'No unit price given and no valid supplier price list entry.', 422, ['line' => $line]);
    }

    private function transition($document, array $from, string $to, string $action, array $extra = [])
    {
        return DB::transaction(function () use ($document, $from, $to, $action, $extra) {
            $locked = $document::query()->lockForUpdate()->findOrFail($document->id);
            if (! in_array($locked->status, $from, true)) {
                throw new BusinessRuleViolation('document_state_invalid', "Cannot move from {$locked->status} to {$to}.", 409);
            }
            $before = $locked->status;
            $locked->forceFill(['status' => $to] + $extra)->save();
            $this->audit->record($action, $locked, ['status' => $before], ['status' => $to] + array_map(fn ($v) => $v instanceof \DateTimeInterface ? $v->format(DATE_ATOM) : $v, $extra));

            return $locked->load('lines');
        });
    }
}

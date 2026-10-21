<?php

namespace App\Domain\Sales\Services;

use App\Domain\Catalogue\Services\UnitConverter;
use App\Domain\Inventory\Services\DocumentNumberer;
use App\Domain\Inventory\Services\ReservationService;
use App\Domain\Organizations\Models\Warehouse;
use App\Domain\Parties\Models\Customer;
use App\Domain\Platform\Services\AuditLogger;
use App\Domain\Sales\Events\SalesOrderConfirmed;
use App\Domain\Sales\Events\SalesOrderCreated;
use App\Domain\Sales\Models\CommercialDocument;
use App\Domain\Sales\Models\Quotation;
use App\Domain\Sales\Models\SalesOrder;
use App\Models\User;
use App\Support\Events\DomainEvents;
use App\Support\Exceptions\BusinessRuleViolation;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Quotations and sales orders.
 *
 *   quotation: draft → sent → accepted (converted to an order) | expired | cancelled
 *   order: draft → confirm → confirmed (stock reserved FEFO) | pending_approval (discount / credit limit)
 *          pending_approval → approve → confirmed | reject → draft
 *          confirmed → partially_delivered → delivered → closed ; draft/pending/confirmed → cancelled (reservations released)
 */
class SalesOrderService
{
    public function __construct(
        private readonly SalesPricing $pricing,
        private readonly SalesPolicy $policy,
        private readonly UnitConverter $units,
        private readonly ReservationService $reservations,
        private readonly DocumentNumberer $numbers,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<array{product_id: string, quantity: string, package_level_code?: ?string, unit_price?: ?string, discount_percent?: ?string}>  $lines
     */
    public function createQuotation(Customer $customer, Warehouse $warehouse, array $lines, ?string $validUntil = null, ?string $notes = null): Quotation
    {
        return DB::transaction(function () use ($customer, $warehouse, $lines, $validUntil, $notes) {
            $this->assertCustomerActive($customer);
            $quote = Quotation::query()->create([
                'number' => $this->numbers->next('QT'), 'customer_id' => $customer->id, 'warehouse_id' => $warehouse->id,
                'status' => 'draft', 'currency_code' => $customer->currency_code, 'valid_until' => $validUntil ?? today()->addDays(14)->toDateString(),
                'notes' => $notes, 'created_by' => Auth::id(),
            ]);
            $this->writeLines($quote, $customer, $warehouse, $lines);
            $this->audit->record('quotation.created', $quote, new: ['number' => $quote->number, 'total' => (string) $quote->total]);

            return $quote->load('lines');
        });
    }

    public function convertQuotation(Quotation $quotation): SalesOrder
    {
        return DB::transaction(function () use ($quotation) {
            $quote = Quotation::query()->lockForUpdate()->findOrFail($quotation->id);
            if (! in_array($quote->status, ['draft', 'sent'], true)) {
                throw new BusinessRuleViolation('quotation_state_invalid', "Quotation is {$quote->status}.", 409);
            }
            if ($quote->valid_until !== null && $quote->valid_until->isPast() && ! $quote->valid_until->isToday()) {
                $quote->forceFill(['status' => 'expired'])->save();
                throw new BusinessRuleViolation('quotation_expired', 'The quotation has expired; issue a new one.', 409);
            }

            // Quoted prices are honoured (no re-pricing): copy lines as quoted.
            $order = $this->createOrder(Customer::query()->findOrFail($quote->customer_id), Warehouse::query()->findOrFail($quote->warehouse_id),
                $quote->lines()->get()->map(fn ($l) => [
                    'product_id' => $l->product_id, 'package_level_code' => $l->package_level_code, 'quantity' => (string) $l->quantity,
                    'unit_price' => (string) $l->unit_price, 'discount_percent' => (string) $l->discount_percent, '_quoted' => true,
                ])->all(), quotationId: $quote->id);

            $quote->forceFill(['status' => 'accepted', 'sales_order_id' => $order->id])->save();
            $this->audit->record('quotation.accepted', $quote, new: ['sales_order' => $order->number]);

            return $order;
        });
    }

    public function createOrder(Customer $customer, Warehouse $warehouse, array $lines, ?string $requestedDate = null, ?string $notes = null, ?string $quotationId = null): SalesOrder
    {
        return DB::transaction(function () use ($customer, $warehouse, $lines, $requestedDate, $notes, $quotationId) {
            $this->assertCustomerActive($customer);
            $order = SalesOrder::query()->create([
                'number' => $this->numbers->next('SO'), 'customer_id' => $customer->id, 'warehouse_id' => $warehouse->id, 'quotation_id' => $quotationId,
                'status' => 'draft', 'currency_code' => $customer->currency_code, 'order_date' => today(), 'requested_date' => $requestedDate,
                'payment_terms_days' => $customer->payment_terms_days, 'notes' => $notes, 'created_by' => Auth::id(),
            ]);
            $this->writeLines($order, $customer, $warehouse, $lines);
            $this->audit->record('sales_order.created', $order, new: ['number' => $order->number, 'total' => (string) $order->total]);
            DomainEvents::raise(new SalesOrderCreated($order->id, $order->organization_id));

            return $order->load('lines');
        });
    }

    public function updateOrder(SalesOrder $order, array $lines): SalesOrder
    {
        return DB::transaction(function () use ($order, $lines) {
            $locked = SalesOrder::query()->lockForUpdate()->findOrFail($order->id);
            if ($locked->status !== 'draft') {
                throw new BusinessRuleViolation('sales_order_locked', "A {$locked->status} order cannot be edited.", 409);
            }
            $this->writeLines($locked, Customer::query()->findOrFail($locked->customer_id), Warehouse::query()->findOrFail($locked->warehouse_id), $lines);
            $this->audit->record('sales_order.updated', $locked, new: ['total' => (string) $locked->total]);

            return $locked->load('lines');
        });
    }

    /** Commit the order: reserve stock, or hold it for approval when a limit is exceeded. */
    public function confirm(SalesOrder $order): SalesOrder
    {
        return DB::transaction(function () use ($order) {
            $locked = SalesOrder::query()->lockForUpdate()->findOrFail($order->id);
            if ($locked->status !== 'draft') {
                throw new BusinessRuleViolation('sales_order_state_invalid', "Order is {$locked->status}.", 409);
            }
            $customer = Customer::query()->findOrFail($locked->customer_id);
            $this->assertCustomerActive($customer);

            $reasons = $this->policy->approvalReasons($customer, (string) $locked->total, $locked->lines()->get());
            if ($reasons !== []) {
                $locked->forceFill(['status' => 'pending_approval', 'approval_reasons' => $reasons])->save();
                $this->audit->record('sales_order.held_for_approval', $locked, new: ['reasons' => $reasons]);

                return $locked->load('lines');
            }

            $this->reserveAndConfirm($locked, null);

            return $locked->load('lines');
        });
    }

    public function decide(SalesOrder $order, bool $approve, User $approver, ?string $reason = null): SalesOrder
    {
        return DB::transaction(function () use ($order, $approve, $approver, $reason) {
            $locked = SalesOrder::query()->lockForUpdate()->findOrFail($order->id);
            if ($locked->status !== 'pending_approval') {
                throw new BusinessRuleViolation('sales_order_state_invalid', "Order is {$locked->status}.", 409);
            }
            $this->policy->assertMayApprove($locked->created_by, $approver);

            if ($approve) {
                $this->reserveAndConfirm($locked, $approver);
            } else {
                if (blank($reason)) {
                    throw new BusinessRuleViolation('decision_notes_required', 'Give a reason when rejecting.');
                }
                $locked->forceFill(['status' => 'draft', 'status_reason' => $reason])->save();
                $this->audit->record('sales_order.rejected', $locked, new: ['reason' => $reason]);
            }

            return $locked->load('lines');
        });
    }

    public function cancel(SalesOrder $order, string $reason): SalesOrder
    {
        return DB::transaction(function () use ($order, $reason) {
            $locked = SalesOrder::query()->lockForUpdate()->findOrFail($order->id);
            if (! in_array($locked->status, ['draft', 'pending_approval', 'confirmed'], true) || $locked->lines()->where('delivered_base_quantity', '>', 0)->exists()) {
                throw new BusinessRuleViolation('sales_order_state_invalid', 'Only undelivered orders can be cancelled; close a partly delivered order instead.', 409);
            }
            foreach ($locked->lines()->get() as $line) {
                $this->reservations->release('sales_order_line', $line->id);
            }
            $locked->forceFill(['status' => 'cancelled', 'status_reason' => $reason])->save();
            $this->audit->record('sales_order.cancelled', $locked, new: ['reason' => $reason]);

            return $locked->load('lines');
        });
    }

    /** Close a partly delivered order; undelivered reservations are released. */
    public function close(SalesOrder $order, string $reason): SalesOrder
    {
        return DB::transaction(function () use ($order, $reason) {
            $locked = SalesOrder::query()->lockForUpdate()->findOrFail($order->id);
            if (! in_array($locked->status, ['confirmed', 'partially_delivered', 'delivered'], true)) {
                throw new BusinessRuleViolation('sales_order_state_invalid', "Order is {$locked->status}.", 409);
            }
            foreach ($locked->lines()->get() as $line) {
                $this->reservations->release('sales_order_line', $line->id);
            }
            $locked->forceFill(['status' => 'closed', 'status_reason' => $reason])->save();
            $this->audit->record('sales_order.closed', $locked, new: ['reason' => $reason]);

            return $locked->load('lines');
        });
    }

    private function reserveAndConfirm(SalesOrder $order, ?User $approver): void
    {
        foreach ($order->lines()->get() as $line) {
            $this->reservations->reserve($line->product_id, $order->warehouse_id, (string) $line->base_quantity, 'sales_order_line', $line->id);
        }
        $order->forceFill(['status' => 'confirmed', 'approved_by' => $approver?->id, 'approved_at' => $approver ? now() : null])->save();
        $this->audit->record('sales_order.confirmed', $order, new: ['approved_by' => $approver?->id, 'total' => (string) $order->total]);
        DomainEvents::raise(new SalesOrderConfirmed($order->id, $order->organization_id));
    }

    private function writeLines(CommercialDocument $document, Customer $customer, Warehouse $warehouse, array $lines): void
    {
        if ($lines === []) {
            throw new BusinessRuleViolation('sales_document_empty', 'Add at least one line.', 422);
        }

        $document->lines()->delete();
        $date = today()->toDateString();
        $totals = ['subtotal' => '0', 'discount' => '0', 'tax' => '0', 'total' => '0'];

        foreach ($lines as $i => $line) {
            $level = $this->units->level($line['product_id'], $line['package_level_code'] ?? null);
            $qty = (string) $line['quantity'];
            $list = $this->pricingOrNull($customer, $warehouse, $line['product_id'], $level->code, $qty, $date);
            $price = isset($line['unit_price']) ? (string) $line['unit_price'] : ($list['price'] ?? throw new BusinessRuleViolation('price_required', 'No price is configured for this product.', 422, ['line' => $i]));

            // A price different from the resolved list price is an override and needs permission (quoted prices are already approved).
            if (empty($line['_quoted']) && isset($line['unit_price']) && ($list === null || bccomp($price, $list['price'], 4) !== 0) && ! Gate::allows('sales.price.override')) {
                throw new BusinessRuleViolation('price_override_not_allowed', 'You may not change the list price.', 403, ['line' => $i, 'list_price' => $list['price'] ?? null]);
            }

            $tax = $this->pricing->tax($line['product_id'], $date);
            $discount = (string) ($line['discount_percent'] ?? '0');
            $amounts = SalesPricing::amounts($qty, $price, $discount, $tax['rate']);

            $document->lines()->create([
                'product_id' => $line['product_id'],
                'package_level_code' => $level->code,
                'quantity' => $qty,
                'base_quantity' => bcmul($qty, (string) $level->base_unit_quantity, 4),
                'unit_price' => $price,
                'list_price' => $list['price'] ?? null,
                'discount_percent' => $discount,
                'tax_category_code' => $tax['category'],
                'tax_rate' => $tax['rate'],
                'line_subtotal' => $amounts['subtotal'],
                'line_discount' => $amounts['discount'],
                'line_tax' => $amounts['tax'],
                'line_total' => $amounts['total'],
            ]);
            foreach (['subtotal', 'discount', 'tax', 'total'] as $k) {
                $totals[$k] = Money::add($totals[$k], $amounts[$k]);
            }
        }

        $document->forceFill(['subtotal' => $totals['subtotal'], 'discount_total' => $totals['discount'], 'tax_total' => $totals['tax'], 'total' => $totals['total']])->save();
    }

    private function pricingOrNull(Customer $customer, Warehouse $warehouse, string $productId, string $level, string $qty, string $date): ?array
    {
        try {
            return $this->pricing->listPrice($customer, $warehouse, $productId, $level, $qty, $date);
        } catch (BusinessRuleViolation $e) {
            if ($e->errorCode === 'price_required') {
                return null;
            }
            throw $e;
        }
    }

    private function assertCustomerActive(Customer $customer): void
    {
        if (! $customer->isActive()) {
            throw new BusinessRuleViolation('customer_not_active', "Customer {$customer->code} is {$customer->status}.", 409);
        }
    }
}

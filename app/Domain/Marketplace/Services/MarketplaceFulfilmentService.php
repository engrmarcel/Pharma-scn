<?php

namespace App\Domain\Marketplace\Services;

use App\Domain\Inventory\Models\Batch;
use App\Domain\Inventory\Services\DocumentNumberer;
use App\Domain\Marketplace\Models\MarketplaceFee;
use App\Domain\Marketplace\Models\MarketplaceInvoice;
use App\Domain\Marketplace\Models\MarketplaceOrder;
use App\Domain\Marketplace\Models\MarketplaceOrderLine;
use App\Domain\Marketplace\Models\MarketplaceShipment;
use App\Domain\Marketplace\Models\TradingRelationship;
use App\Domain\Parties\Models\Supplier;
use App\Domain\Platform\Services\AuditLogger;
use App\Domain\Procurement\Models\PurchaseOrder;
use App\Domain\Procurement\Services\GoodsReceiptService;
use App\Domain\Procurement\Services\SupplierInvoiceService;
use App\Domain\Sales\Models\SalesOrder;
use App\Domain\Sales\Services\FulfilmentService;
use App\Support\Exceptions\BusinessRuleViolation;
use App\Support\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Marketplace fulfilment (spec §14.2), each step posted by its own side:
 *
 *   supplier ships     → a delivery on the supplier's sales order (stock out FEFO, cost of goods);
 *                        the shipment carries the batch numbers and expiry dates that left
 *   buyer receives     → a goods receipt on the buyer's purchase order with those same batches
 *   supplier invoices  → the supplier's sales invoice for what was delivered; the platform fee accrues
 *   buyer records it   → the buyer's supplier invoice, three-way matched against PO and receipt
 *
 * Payment status is read from each side's own books and labelled with its source.
 */
class MarketplaceFulfilmentService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly MarketplaceOrderService $orders,
        private readonly FulfilmentService $fulfilment,
        private readonly GoodsReceiptService $receipts,
        private readonly SupplierInvoiceService $supplierInvoices,
        private readonly DocumentNumberer $numbers,
        private readonly AuditLogger $audit,
    ) {}

    /** @param list<array{order_line_id: int, quantity: string}> $lines */
    public function ship(MarketplaceOrder $order, array $lines, ?string $carrier, ?string $tracking): MarketplaceShipment
    {
        return DB::transaction(function () use ($order, $lines, $carrier, $tracking) {
            $locked = $this->lock($order, 'supplier', ['accepted', 'partially_shipped', 'partially_received']);
            $orderLines = $locked->lines()->get()->keyBy('id');

            $deliveryLines = [];
            foreach ($lines as $i => $line) {
                $ol = $orderLines[$line['order_line_id']] ?? throw new BusinessRuleViolation('order_line_unknown', 'That line is not on this order.', 422, ['line' => $i]);
                if (bccomp((string) $line['quantity'], bcsub((string) $ol->quantity, (string) $ol->shipped_quantity, 4), 4) > 0) {
                    throw new BusinessRuleViolation('ship_quantity_invalid', 'You cannot ship more than is outstanding on the line.', 422, ['line' => $i]);
                }
                $deliveryLines[] = ['sales_order_line_id' => $ol->supplier_sales_order_line_id, 'quantity' => (string) $line['quantity']];
            }

            $delivery = $this->fulfilment->deliver(SalesOrder::query()->findOrFail($locked->supplier_sales_order_id), $deliveryLines, "Marketplace order {$locked->number}");
            $issued = $delivery->lines()->get();
            $batches = Batch::query()->whereIn('id', $issued->pluck('batch_id'))->get()->keyBy('id');

            $shipment = MarketplaceShipment::query()->create([
                'marketplace_order_id' => $locked->id, 'number' => $this->numbers->next('MSH'), 'supplier_delivery_id' => $delivery->id,
                'carrier' => $carrier, 'tracking_reference' => $tracking, 'shipped_at' => now(),
            ]);
            foreach ($lines as $line) {
                $ol = $orderLines[$line['order_line_id']];
                $shipment->lines()->create([
                    'marketplace_order_line_id' => $ol->id, 'product_id' => $ol->product_id, 'package_level_code' => $ol->package_level_code, 'quantity' => $line['quantity'],
                    // The batch evidence that travels to the buyer's goods receipt.
                    'batches' => $issued->where('sales_order_line_id', $ol->supplier_sales_order_line_id)->map(fn ($d) => [
                        'batch_number' => $batches[$d->batch_id]->batch_number,
                        'expiry_date' => $batches[$d->batch_id]->expiry_date?->toDateString(),
                        'base_quantity' => (string) $d->base_quantity,
                    ])->values()->all(),
                ]);
                $ol->forceFill(['shipped_quantity' => bcadd((string) $ol->shipped_quantity, (string) $line['quantity'], 4)])->save();
            }

            $allShipped = $locked->lines()->get()->every(fn ($l) => bccomp((string) $l->shipped_quantity, (string) $l->quantity, 4) >= 0);
            $locked->forceFill(['status' => $allShipped ? 'shipped' : 'partially_shipped'])->save();
            $this->audit->record('marketplace_order.shipped', $delivery, new: ['order' => $locked->number, 'shipment' => $shipment->number, 'tracking' => $tracking]);

            return $shipment->load('lines');
        });
    }

    /**
     * Buyer: receive a shipment into the purchase order with the supplier's batches.
     * Quantities default to what was shipped; less may be entered (the rest is a matter for a dispute).
     *
     * @param  array<int, string>  $received  shipment line id => quantity received (package units)
     */
    public function receive(MarketplaceShipment $shipment, array $received = [], ?string $notes = null): MarketplaceShipment
    {
        return DB::transaction(function () use ($shipment, $received, $notes) {
            $locked = MarketplaceShipment::query()->lockForUpdate()->findOrFail($shipment->id);
            $order = $this->lock(MarketplaceOrder::query()->findOrFail($locked->marketplace_order_id), 'buyer', ['shipped', 'partially_shipped', 'partially_received']);
            if ($locked->status !== 'in_transit') {
                throw new BusinessRuleViolation('shipment_already_received', 'This shipment has already been received.', 409);
            }
            $orderLines = $order->lines()->get()->keyBy('id');

            $grnLines = [];
            $short = false;
            foreach ($locked->lines()->get() as $sl) {
                $quantity = (string) ($received[$sl->id] ?? $sl->quantity);
                if (bccomp($quantity, '0', 4) < 0 || bccomp($quantity, (string) $sl->quantity, 4) > 0) {
                    throw new BusinessRuleViolation('receive_quantity_invalid', 'Receive between zero and the shipped quantity.', 422, ['shipment_line_id' => $sl->id]);
                }
                $short = $short || bccomp($quantity, (string) $sl->quantity, 4) < 0;
                $ol = $orderLines[$sl->marketplace_order_line_id];
                $shippedBase = array_reduce($sl->batches, fn ($c, $b) => bcadd($c, (string) $b['base_quantity'], 4), '0');
                $perPack = bcdiv($shippedBase, (string) $sl->quantity, 8);

                // Spread the received quantity over the shipped batches, earliest expiry first.
                $remainingBase = bcmul($quantity, $perPack, 4);
                foreach (collect($sl->batches)->sortBy('expiry_date') as $b) {
                    if (bccomp($remainingBase, '0', 4) <= 0) {
                        break;
                    }
                    $take = bccomp((string) $b['base_quantity'], $remainingBase, 4) <= 0 ? (string) $b['base_quantity'] : $remainingBase;
                    $grnLines[] = ['purchase_order_line_id' => $ol->buyer_purchase_order_line_id, 'batch_number' => $b['batch_number'], 'expiry_date' => $b['expiry_date'],
                        'quantity' => bcdiv($take, $perPack, 4)];
                    $remainingBase = bcsub($remainingBase, $take, 4);
                }
                $sl->forceFill(['received_quantity' => $quantity])->save();
                $ol->forceFill(['received_quantity' => bcadd((string) $ol->received_quantity, $quantity, 4)])->save();
            }

            $grn = $grnLines === [] ? null : $this->receipts->receive(PurchaseOrder::query()->findOrFail($order->buyer_purchase_order_id), $grnLines, $locked->number, $notes);
            $locked->forceFill(['status' => $short ? 'received_short' : 'received', 'received_at' => now(), 'buyer_goods_receipt_id' => $grn?->id])->save();

            $allReceived = $order->lines()->get()->every(fn ($l) => bccomp((string) $l->received_quantity, (string) $l->quantity, 4) >= 0);
            $allShipped = $order->lines()->get()->every(fn ($l) => bccomp((string) $l->shipped_quantity, (string) $l->quantity, 4) >= 0);
            $order->forceFill(['status' => $allReceived ? 'received' : ($allShipped ? 'partially_received' : 'partially_shipped')])->save();
            $this->audit->record('marketplace_shipment.received', $grn, new: ['order' => $order->number, 'shipment' => $locked->number, 'short' => $short]);

            return $locked->load('lines');
        });
    }

    /** Supplier: invoice what has been delivered and not yet invoiced; the platform fee accrues on it. */
    public function invoice(MarketplaceOrder $order): MarketplaceInvoice
    {
        return DB::transaction(function () use ($order) {
            $locked = $this->lock($order, 'supplier', ['partially_shipped', 'shipped', 'partially_received', 'received']);
            $sales = $this->fulfilment->invoiceOrder(SalesOrder::query()->findOrFail($locked->supplier_sales_order_id));
            $bySoLine = $locked->lines()->get()->keyBy('supplier_sales_order_line_id');
            $net = bcsub((string) $sales->subtotal, (string) $sales->discount_total, 4);

            $invoice = MarketplaceInvoice::query()->create([
                'marketplace_order_id' => $locked->id, 'supplier_sales_invoice_id' => $sales->id, 'number' => $sales->number, 'currency_code' => $sales->currency_code,
                'net_amount' => $net, 'tax_amount' => (string) $sales->tax_total, 'total' => (string) $sales->total,
                'invoice_date' => $sales->invoice_date, 'due_date' => $sales->due_date,
                'lines' => $sales->lines()->get()->map(fn ($l) => [
                    'marketplace_order_line_id' => $bySoLine[$l->sales_order_line_id]?->id, 'product_id' => $l->product_id, 'package_level_code' => $l->package_level_code,
                    'quantity' => (string) $l->quantity, 'unit_price' => (string) $l->unit_price, 'tax_rate' => (string) $l->tax_rate,
                ])->values()->all(),
            ]);
            $this->accrueFee($locked, $invoice);
            $this->audit->record('marketplace_order.invoiced', $sales, new: ['order' => $locked->number, 'invoice' => $sales->number, 'total' => (string) $sales->total]);

            return $invoice;
        });
    }

    /** Buyer: record the supplier's invoice in its own books, matched to the purchase order and receipts. */
    public function recordSupplierInvoice(MarketplaceInvoice $invoice): MarketplaceInvoice
    {
        return DB::transaction(function () use ($invoice) {
            $locked = MarketplaceInvoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $order = MarketplaceOrder::query()->findOrFail($locked->marketplace_order_id);
            if ($this->orders->side($order) !== 'buyer') {
                throw new BusinessRuleViolation('order_wrong_side', 'Only the buyer records the supplier invoice.', 403);
            }
            if ($locked->buyer_supplier_invoice_id !== null) {
                throw new BusinessRuleViolation('supplier_invoice_recorded', 'This invoice is already in your books.', 409);
            }
            $lines = MarketplaceOrderLine::query()->whereIn('id', collect($locked->lines)->pluck('marketplace_order_line_id'))->get()->keyBy('id');
            $supplier = Supplier::query()->findOrFail(TradingRelationship::query()->findOrFail($order->trading_relationship_id)->buyer_supplier_id);

            $recorded = $this->supplierInvoices->record($supplier, PurchaseOrder::query()->findOrFail($order->buyer_purchase_order_id), $locked->number,
                $locked->invoice_date->toDateString(), $locked->due_date?->toDateString(),
                collect($locked->lines)->map(fn ($l) => ['purchase_order_line_id' => $lines[$l['marketplace_order_line_id']]->buyer_purchase_order_line_id,
                    'quantity' => $l['quantity'], 'unit_price' => $l['unit_price'], 'tax_rate' => $l['tax_rate']])->all());
            $locked->forceFill(['buyer_supplier_invoice_id' => $recorded->id])->save();

            return $locked;
        });
    }

    /** Invoices with payment status from each side's own books (labelled with the source). */
    public function invoices(MarketplaceOrder $order): array
    {
        $side = $this->orders->side($order);

        return MarketplaceInvoice::query()->where('marketplace_order_id', $order->id)->orderBy('created_at')->get()->map(function (MarketplaceInvoice $i) use ($side) {
            $sales = DB::table('sales_invoices')->where('id', $i->supplier_sales_invoice_id)->first(['payment_status', 'amount_paid', 'amount_credited', 'total']);
            $bought = $i->buyer_supplier_invoice_id ? DB::table('supplier_invoices')->where('id', $i->buyer_supplier_invoice_id)->first(['number', 'status', 'match_status']) : null;

            return $i->only(['id', 'number', 'currency_code', 'net_amount', 'tax_amount', 'total', 'invoice_date', 'due_date', 'lines']) + [
                'payment_status' => [
                    'status' => $sales->payment_status,
                    'open_balance' => bcsub(bcsub((string) $sales->total, (string) $sales->amount_paid, 4), (string) $sales->amount_credited, 4),
                    'source' => 'As recorded in the supplier\'s receivables.',
                ],
                'buyer_record' => $side === 'buyer' ? ($bought ? (array) $bought : null) : ($bought ? ['recorded' => true] : ['recorded' => false]),
            ];
        })->values()->all();
    }

    private function accrueFee(MarketplaceOrder $order, MarketplaceInvoice $invoice): MarketplaceFee
    {
        $rate = (string) config('marketplace.fee_percent');
        $amount = Money::round(bcdiv(bcmul((string) $invoice->net_amount, $rate, 10), '100', 10));
        $minimum = (string) config('marketplace.fee_minimum');
        if (bccomp($amount, $minimum, 4) < 0) {
            $amount = $minimum;
        }

        $fee = MarketplaceFee::query()->create([
            'marketplace_order_id' => $order->id, 'marketplace_invoice_id' => $invoice->id, 'supplier_organization_id' => $order->supplier_organization_id,
            'currency_code' => $invoice->currency_code, 'basis_amount' => (string) $invoice->net_amount, 'rate_percent' => $rate, 'amount' => $amount,
        ]);
        $this->audit->record('marketplace_fee.accrued', null, new: ['fee_id' => $fee->id, 'order' => $order->number, 'basis' => (string) $invoice->net_amount, 'rate' => $rate, 'amount' => $amount]);

        return $fee;
    }

    private function lock(MarketplaceOrder $order, string $side, array $statuses): MarketplaceOrder
    {
        $locked = MarketplaceOrder::query()->lockForUpdate()->findOrFail($order->id);
        if ($this->orders->side($locked) !== $side) {
            throw new BusinessRuleViolation('order_wrong_side', "Only the {$side} can do this.", 403);
        }
        if (! in_array($locked->status, $statuses, true)) {
            throw new BusinessRuleViolation('marketplace_order_state_invalid', "The order is {$locked->status}.", 409);
        }

        return $locked;
    }
}

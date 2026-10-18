<?php

namespace App\Domain\Sales\Services;

use App\Domain\Finance\Services\AccountingBridge;
use App\Domain\Inventory\Models\Batch;
use App\Domain\Inventory\Services\DocumentNumberer;
use App\Domain\Inventory\Services\InventoryLedger;
use App\Domain\Inventory\Services\ReservationService;
use App\Domain\Inventory\Support\StockLine;
use App\Domain\Platform\Services\AuditLogger;
use App\Domain\Sales\Events\SaleCompleted;
use App\Domain\Sales\Models\CreditNote;
use App\Domain\Sales\Models\Delivery;
use App\Domain\Sales\Models\DeliveryLine;
use App\Domain\Sales\Models\SalesInvoice;
use App\Domain\Sales\Models\SalesInvoiceLine;
use App\Domain\Sales\Models\SalesOrder;
use App\Support\Exceptions\BusinessRuleViolation;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Delivery, invoicing and credit notes. Each runs in one transaction with
 * its stock movements and journal entries, so an invoice can never claim
 * goods that did not leave stock, and stock never leaves without its cost.
 */
class FulfilmentService
{
    public function __construct(
        private readonly ReservationService $reservations,
        private readonly InventoryLedger $ledger,
        private readonly AccountingBridge $accounting,
        private readonly DocumentNumberer $numbers,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<array{sales_order_line_id: string, quantity: string}>  $lines  quantity in the order line's package level
     */
    public function deliver(SalesOrder $order, array $lines, ?string $notes = null): Delivery
    {
        return DB::transaction(function () use ($order, $lines, $notes) {
            $so = SalesOrder::query()->lockForUpdate()->findOrFail($order->id);
            if (! in_array($so->status, ['confirmed', 'partially_delivered'], true)) {
                throw new BusinessRuleViolation('sales_order_not_deliverable', "Cannot deliver a {$so->status} order.", 409);
            }
            $soLines = $so->lines()->lockForUpdate()->get()->keyBy('id');

            $delivery = Delivery::query()->create([
                'number' => $this->numbers->next('DN'), 'sales_order_id' => $so->id, 'customer_id' => $so->customer_id,
                'warehouse_id' => $so->warehouse_id, 'delivered_by' => Auth::id(), 'delivered_at' => now(), 'notes' => $notes,
            ]);

            $deltas = [];
            $postings = [];
            foreach ($lines as $i => $line) {
                $soLine = $soLines[$line['sales_order_line_id']] ?? throw new BusinessRuleViolation('sales_order_line_unknown', 'Line does not belong to this order.', 422, ['line' => $i]);
                $base = bcmul((string) $line['quantity'], bcdiv((string) $soLine->base_quantity, (string) $soLine->quantity, 8), 4);
                $outstanding = bcsub((string) $soLine->base_quantity, (string) $soLine->delivered_base_quantity, 4);
                if (bccomp($base, '0', 4) <= 0 || bccomp($base, $outstanding, 4) > 0) {
                    throw new BusinessRuleViolation('delivery_quantity_invalid', "Line {$i}: deliver between 0 and the outstanding quantity.", 422, ['outstanding_base' => $outstanding]);
                }

                $shipped = $this->reservations->consumeQuantity('sales_order_line', $soLine->id, $base);
                $postings[] = $shipped['posting_id'];
                $costs = Batch::query()->whereIn('id', array_keys($shipped['batches']))->pluck('unit_cost', 'id');
                foreach ($shipped['batches'] as $batchId => $qty) {
                    DeliveryLine::query()->create(['delivery_id' => $delivery->id, 'sales_order_line_id' => $soLine->id, 'batch_id' => $batchId, 'base_quantity' => $qty, 'unit_cost' => $costs[$batchId]]);
                    $deltas[] = ['batch_id' => $batchId, 'quantity' => $qty];
                }
                $soLine->forceFill(['delivered_base_quantity' => bcadd((string) $soLine->delivered_base_quantity, $base, 4)])->save();
            }

            $journal = $this->accounting->deliveryCost('delivery', $delivery->id, "Delivery {$delivery->number} (cost of goods sold)", $deltas, today()->toDateString());
            $complete = $so->lines()->get()->every(fn ($l) => bccomp((string) $l->delivered_base_quantity, (string) $l->base_quantity, 4) >= 0);
            $so->forceFill(['status' => $complete ? 'delivered' : 'partially_delivered'])->save();
            $delivery->forceFill(['posting_id' => $postings[0] ?? null, 'journal_entry_id' => $journal?->id])->save();

            $this->audit->record('delivery.posted', $delivery, new: ['number' => $delivery->number, 'sales_order' => $so->number, 'order_status' => $so->status]);

            return $delivery->load('lines');
        });
    }

    /** Invoice every delivered-but-not-invoiced quantity on the order. */
    public function invoiceOrder(SalesOrder $order): SalesInvoice
    {
        return DB::transaction(function () use ($order) {
            $so = SalesOrder::query()->lockForUpdate()->findOrFail($order->id);
            $billable = $so->lines()->lockForUpdate()->get()->filter(fn ($l) => bccomp((string) $l->delivered_base_quantity, (string) $l->invoiced_base_quantity, 4) > 0);
            if ($billable->isEmpty()) {
                throw new BusinessRuleViolation('nothing_to_invoice', 'There are no delivered, uninvoiced quantities on this order.', 409);
            }

            $invoice = SalesInvoice::query()->create([
                'number' => $this->numbers->next('INV'), 'customer_id' => $so->customer_id, 'warehouse_id' => $so->warehouse_id, 'sales_order_id' => $so->id,
                'source' => 'sales_order', 'status' => 'issued', 'currency_code' => $so->currency_code, 'invoice_date' => today(),
                'due_date' => today()->addDays($so->payment_terms_days), 'created_by' => Auth::id(),
            ]);

            $totals = ['subtotal' => '0', 'discount' => '0', 'tax' => '0', 'total' => '0'];
            foreach ($billable as $soLine) {
                $base = bcsub((string) $soLine->delivered_base_quantity, (string) $soLine->invoiced_base_quantity, 4);
                $qty = bcdiv($base, bcdiv((string) $soLine->base_quantity, (string) $soLine->quantity, 8), 4);
                $amounts = SalesPricing::amounts($qty, (string) $soLine->unit_price, (string) $soLine->discount_percent, (string) $soLine->tax_rate);

                $invoice->lines()->create([
                    'sales_order_line_id' => $soLine->id, 'product_id' => $soLine->product_id, 'package_level_code' => $soLine->package_level_code,
                    'quantity' => $qty, 'base_quantity' => $base, 'unit_price' => $soLine->unit_price, 'list_price' => $soLine->list_price,
                    'discount_percent' => $soLine->discount_percent, 'tax_category_code' => $soLine->tax_category_code, 'tax_rate' => $soLine->tax_rate,
                    'line_subtotal' => $amounts['subtotal'], 'line_discount' => $amounts['discount'], 'line_tax' => $amounts['tax'], 'line_total' => $amounts['total'],
                ]);
                $soLine->forceFill(['invoiced_base_quantity' => $soLine->delivered_base_quantity])->save();
                foreach (['subtotal', 'discount', 'tax', 'total'] as $k) {
                    $totals[$k] = Money::add($totals[$k], $amounts[$k]);
                }
            }

            $invoice->forceFill(['subtotal' => $totals['subtotal'], 'discount_total' => $totals['discount'], 'tax_total' => $totals['tax'], 'total' => $totals['total']])->save();
            $journal = $this->accounting->salesInvoice($invoice);
            $invoice->forceFill(['journal_entry_id' => $journal->id])->save();

            $this->audit->record('sales_invoice.issued', $invoice, new: ['number' => $invoice->number, 'total' => (string) $invoice->total, 'sales_order' => $so->number]);
            event(new SaleCompleted($invoice->id, $invoice->organization_id, 'sales_order'));

            return $invoice->load('lines');
        });
    }

    /**
     * Issue an invoice for goods already handed over outside a sales order
     * (POS). Lines carry final prices, discounts and tax as sold.
     *
     * @param  list<array<string, mixed>>  $lines  sales_invoice_lines attributes
     */
    public function issueDirectInvoice(string $customerId, string $warehouseId, string $source, string $currency, array $lines): SalesInvoice
    {
        return DB::transaction(function () use ($customerId, $warehouseId, $source, $currency, $lines) {
            $invoice = SalesInvoice::query()->create([
                'number' => $this->numbers->next('INV'), 'customer_id' => $customerId, 'warehouse_id' => $warehouseId, 'source' => $source,
                'status' => 'issued', 'currency_code' => $currency, 'invoice_date' => today(), 'due_date' => today(), 'created_by' => Auth::id(),
            ]);

            $totals = ['subtotal' => '0', 'discount' => '0', 'tax' => '0', 'total' => '0'];
            $created = [];
            foreach ($lines as $line) {
                $created[] = $invoice->lines()->create($line);
                foreach (['subtotal' => 'line_subtotal', 'discount' => 'line_discount', 'tax' => 'line_tax', 'total' => 'line_total'] as $k => $col) {
                    $totals[$k] = Money::add($totals[$k], (string) $line[$col]);
                }
            }
            $invoice->forceFill(['subtotal' => $totals['subtotal'], 'discount_total' => $totals['discount'], 'tax_total' => $totals['tax'], 'total' => $totals['total']])->save();
            $journal = $this->accounting->salesInvoice($invoice);
            $invoice->forceFill(['journal_entry_id' => $journal->id])->save();

            $this->audit->record('sales_invoice.issued', $invoice, new: ['number' => $invoice->number, 'total' => (string) $invoice->total, 'source' => $source]);
            event(new SaleCompleted($invoice->id, $invoice->organization_id, $source));

            // Lines in the same order as given, so callers can link their own lines to them.
            return $invoice->setRelation('lines', collect($created));
        });
    }

    /**
     * Credit (part of) an invoice. Returned goods are restocked as quarantined
     * unless restock_state "available" is requested by someone allowed to, for
     * a batch that is still sellable.
     *
     * @param  list<array{sales_invoice_line_id: string, quantity: string, batch_id?: ?string, restock_state?: ?string}>  $lines
     */
    public function creditNote(SalesInvoice $invoice, string $reasonCode, array $lines, ?string $returnWarehouseId = null, ?string $notes = null, bool $allowCreditBalance = false): CreditNote
    {
        return DB::transaction(function () use ($invoice, $reasonCode, $lines, $returnWarehouseId, $notes, $allowCreditBalance) {
            $inv = SalesInvoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if ($inv->status !== 'issued') {
                throw new BusinessRuleViolation('sales_invoice_state_invalid', "Invoice is {$inv->status}.", 409);
            }
            $invLines = $inv->lines()->lockForUpdate()->get()->keyBy('id');

            $note = CreditNote::query()->create([
                'number' => $this->numbers->next('CN'), 'sales_invoice_id' => $inv->id, 'customer_id' => $inv->customer_id, 'reason_code' => $reasonCode,
                'notes' => $notes, 'credit_date' => today(), 'currency_code' => $inv->currency_code, 'subtotal' => 0, 'tax_total' => 0, 'total' => 0,
                'return_warehouse_id' => $returnWarehouseId, 'created_by' => Auth::id(),
            ]);

            $subtotal = '0';
            $tax = '0';
            $stockLines = [];
            $restocked = [];
            foreach ($lines as $i => $line) {
                /** @var SalesInvoiceLine $invLine */
                $invLine = $invLines[$line['sales_invoice_line_id']] ?? throw new BusinessRuleViolation('sales_invoice_line_unknown', 'Line does not belong to this invoice.', 422, ['line' => $i]);
                $perUnit = bcdiv((string) $invLine->base_quantity, (string) $invLine->quantity, 8);
                $base = bcmul((string) $line['quantity'], $perUnit, 4);
                $creditable = bcsub((string) $invLine->base_quantity, (string) $invLine->credited_base_quantity, 4);
                if (bccomp($base, '0', 4) <= 0 || bccomp($base, $creditable, 4) > 0) {
                    throw new BusinessRuleViolation('credit_quantity_invalid', 'Credit more than zero and no more than invoiced minus already credited.', 422, ['line' => $i, 'creditable_base' => $creditable]);
                }

                // Net (after discount) and tax, pro rata to the credited share of the invoice line.
                $share = bcdiv($base, (string) $invLine->base_quantity, 10);
                $lineNet = Money::round(bcmul(bcsub((string) $invLine->line_subtotal, (string) $invLine->line_discount, 4), $share, 10));
                $lineTax = Money::round(bcmul((string) $invLine->line_tax, $share, 10));

                $batchId = $line['batch_id'] ?? null;
                $state = $line['restock_state'] ?? ($batchId ? 'quarantined' : null);
                if ($batchId !== null) {
                    $this->assertReturnable($inv, $invLine, $batchId, $returnWarehouseId, $state, $i);
                    $stockLines[] = new StockLine($returnWarehouseId, $batchId, $state, $base, 'sale_return');
                    $restocked[] = ['batch_id' => $batchId, 'quantity' => $base];
                }

                $note->lines()->create(['sales_invoice_line_id' => $invLine->id, 'base_quantity' => $base, 'line_subtotal' => $lineNet, 'line_tax' => $lineTax, 'batch_id' => $batchId, 'restock_state' => $batchId ? $state : null]);
                $invLine->forceFill(['credited_base_quantity' => bcadd((string) $invLine->credited_base_quantity, $base, 4)])->save();
                $subtotal = Money::add($subtotal, $lineNet);
                $tax = Money::add($tax, $lineTax);
            }

            $total = Money::add($subtotal, $tax);
            $open = $inv->openBalance();
            // Refund flows (POS) may credit a paid invoice; the resulting customer credit is then paid out.
            if (! $allowCreditBalance && bccomp($total, $open, 4) > 0) {
                throw new BusinessRuleViolation('credit_exceeds_open_balance', "The credit ({$total}) exceeds the invoice's open balance ({$open}); refunds of paid amounts arrive with POS refunds.", 409);
            }

            $note->forceFill(['subtotal' => $subtotal, 'tax_total' => $tax, 'total' => $total])->save();
            $postingId = $stockLines === [] ? null : $this->ledger->post($stockLines, 'customer_return', 'credit_note', $note->id);
            $journal = $this->accounting->creditNote($note, $restocked);
            $note->forceFill(['posting_id' => $postingId, 'journal_entry_id' => $journal->id])->save();

            $credited = bcadd((string) $inv->amount_credited, $total, 4);
            $inv->forceFill(['amount_credited' => $credited])->save();
            $inv->forceFill(['payment_status' => $inv->settlementStatus()])->save();

            $this->audit->record('credit_note.issued', $note, new: ['number' => $note->number, 'invoice' => $inv->number, 'total' => $total, 'restocked_lines' => count($stockLines)]);

            return $note->load('lines');
        });
    }

    private function assertReturnable(SalesInvoice $invoice, SalesInvoiceLine $invLine, string $batchId, ?string $warehouseId, ?string $state, int $i): void
    {
        if ($warehouseId === null) {
            throw new BusinessRuleViolation('return_warehouse_required', 'Choose the warehouse receiving the returned goods.', 422);
        }
        $delivered = $invoice->source === 'pos'
            ? DB::table('pos_sale_allocations as a')->join('pos_sale_lines as l', 'l.id', '=', 'a.pos_sale_line_id')
                ->where('l.sales_invoice_line_id', $invLine->id)->where('a.batch_id', $batchId)->exists()
            : DeliveryLine::query()->where('sales_order_line_id', $invLine->sales_order_line_id)->where('batch_id', $batchId)->exists();
        if (! $delivered) {
            throw new BusinessRuleViolation('return_batch_mismatch', 'That batch was not delivered on this invoice line.', 422, ['line' => $i]);
        }
        if (! in_array($state, ['quarantined', 'damaged', 'available'], true)) {
            throw new BusinessRuleViolation('restock_state_invalid', 'Restock as quarantined, damaged or available.', 422, ['line' => $i]);
        }
        if ($state === 'available') {
            if (! Gate::allows('sales.returns.restock')) {
                throw new BusinessRuleViolation('restock_not_allowed', 'Returning goods straight to sellable stock needs the restock permission.', 403, ['line' => $i]);
            }
            $block = Batch::query()->findOrFail($batchId)->saleBlockReason();
            if ($block !== null) {
                throw new BusinessRuleViolation('batch_not_sellable', "Returned batch is {$block}; restock it as quarantined.", 409, ['line' => $i]);
            }
        }
    }
}

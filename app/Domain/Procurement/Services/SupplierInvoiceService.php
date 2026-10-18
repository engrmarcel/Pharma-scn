<?php

namespace App\Domain\Procurement\Services;

use App\Domain\Finance\Services\AccountingBridge;
use App\Domain\Inventory\Services\DocumentNumberer;
use App\Domain\Parties\Models\Supplier;
use App\Domain\Platform\Services\AuditLogger;
use App\Domain\Procurement\Models\PurchaseOrder;
use App\Domain\Procurement\Models\PurchaseOrderLine;
use App\Domain\Procurement\Models\SupplierInvoice;
use App\Models\User;
use App\Support\Exceptions\BusinessRuleViolation;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Supplier invoices with three-way matching (order ↔ receipt ↔ invoice):
 *   unmatched - invoices more than has been received (blocks approval unless overridden with a reason)
 *   variance  - quantities fine but unit price differs from the order (approvable, flagged)
 *   matched   - quantities and prices agree
 * Payment, payables and ledger postings arrive with finance (Phase 2.4).
 */
class SupplierInvoiceService
{
    public function __construct(
        private readonly DocumentNumberer $numbers,
        private readonly ProcurementPolicy $policy,
        private readonly AuditLogger $audit,
        private readonly AccountingBridge $accounting,
    ) {}

    /**
     * @param  list<array{purchase_order_line_id?: ?int, description?: ?string, quantity: string, unit_price: string, tax_rate?: ?string}>  $lines
     */
    public function record(Supplier $supplier, ?PurchaseOrder $order, string $supplierInvoiceNumber, string $invoiceDate, ?string $dueDate, array $lines): SupplierInvoice
    {
        if ($order !== null && $order->supplier_id !== $supplier->id) {
            throw new BusinessRuleViolation('supplier_mismatch', 'The purchase order belongs to a different supplier.', 422);
        }
        $duplicate = SupplierInvoice::query()->where('supplier_id', $supplier->id)->where('supplier_invoice_number', $supplierInvoiceNumber)->exists();
        if ($duplicate) {
            throw new BusinessRuleViolation('supplier_invoice_duplicate', "Invoice {$supplierInvoiceNumber} from this supplier is already recorded.", 409);
        }

        return DB::transaction(function () use ($supplier, $order, $supplierInvoiceNumber, $invoiceDate, $dueDate, $lines) {
            $poLines = $order ? $order->lines()->get()->keyBy('id') : collect();
            $invoice = SupplierInvoice::query()->create([
                'number' => $this->numbers->next('SINV'),
                'supplier_invoice_number' => $supplierInvoiceNumber,
                'supplier_id' => $supplier->id,
                'purchase_order_id' => $order?->id,
                'invoice_date' => $invoiceDate,
                'due_date' => $dueDate ?? Carbon::parse($invoiceDate)->addDays($supplier->payment_terms_days)->toDateString(),
                'currency_code' => $order?->currency_code ?? $supplier->currency_code,
                'subtotal' => 0, 'tax_total' => 0, 'total' => 0,
                'status' => 'draft',
                'created_by' => Auth::id(),
            ]);

            $subtotal = '0';
            $tax = '0';
            foreach ($lines as $i => $line) {
                $poLine = isset($line['purchase_order_line_id']) ? ($poLines[$line['purchase_order_line_id']] ?? throw new BusinessRuleViolation('purchase_order_line_unknown', 'Line does not belong to the purchase order.', 422, ['line' => $i])) : null;
                [$lineSubtotal, $lineTax] = Money::line((string) $line['quantity'], (string) $line['unit_price'], (string) ($line['tax_rate'] ?? '0'));

                $invoice->lines()->create([
                    'purchase_order_line_id' => $poLine?->id,
                    'description' => $line['description'] ?? ($poLine ? "PO line {$poLine->id}" : 'Charge'),
                    'quantity' => $line['quantity'],
                    'base_quantity' => $poLine ? bcmul((string) $line['quantity'], bcdiv((string) $poLine->base_quantity, (string) $poLine->quantity, 8), 4) : null,
                    'unit_price' => $line['unit_price'],
                    'tax_rate' => $line['tax_rate'] ?? '0',
                    'line_subtotal' => $lineSubtotal,
                    'line_tax' => $lineTax,
                ]);
                $subtotal = Money::add($subtotal, $lineSubtotal);
                $tax = Money::add($tax, $lineTax);
            }

            $invoice->forceFill(['subtotal' => $subtotal, 'tax_total' => $tax, 'total' => Money::add($subtotal, $tax)])->save();
            $this->match($invoice);
            $this->audit->record('supplier_invoice.recorded', $invoice, new: ['number' => $invoice->number, 'supplier_invoice_number' => $supplierInvoiceNumber, 'total' => (string) $invoice->total, 'match_status' => $invoice->match_status]);

            return $invoice->load('lines');
        });
    }

    public function approve(SupplierInvoice $invoice, User $approver, ?string $overrideReason = null): SupplierInvoice
    {
        return DB::transaction(function () use ($invoice, $approver, $overrideReason) {
            $locked = SupplierInvoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if ($locked->status !== 'draft') {
                throw new BusinessRuleViolation('supplier_invoice_state_invalid', "Invoice is {$locked->status}.", 409);
            }
            $this->policy->assertMayApprove($locked->created_by, $approver);

            // Re-match under lock: receipts or other invoices may have changed since recording.
            $this->match($locked, lock: true);
            if ($locked->match_status === 'unmatched' && blank($overrideReason)) {
                throw new BusinessRuleViolation('supplier_invoice_unmatched', 'The invoice bills more than has been received. Provide an override reason to approve anyway.', 409, $locked->match_details ?? []);
            }

            foreach ($locked->lines()->whereNotNull('purchase_order_line_id')->get() as $line) {
                PurchaseOrderLine::query()->whereKey($line->purchase_order_line_id)->increment('invoiced_base_quantity', (string) $line->base_quantity);
            }

            $journal = $this->accounting->supplierInvoice($locked);
            $locked->forceFill(['status' => 'approved', 'approved_by' => $approver->id, 'approved_at' => now(), 'journal_entry_id' => $journal->id])->save();
            $this->audit->record('supplier_invoice.approved', $locked, new: ['match_status' => $locked->match_status, 'override_reason' => $overrideReason]);

            return $locked->load('lines');
        });
    }

    public function cancel(SupplierInvoice $invoice, string $reason): SupplierInvoice
    {
        if ($invoice->status !== 'draft') {
            throw new BusinessRuleViolation('supplier_invoice_state_invalid', 'Only draft invoices can be cancelled.', 409);
        }
        $invoice->forceFill(['status' => 'cancelled'])->save();
        $this->audit->record('supplier_invoice.cancelled', $invoice, new: ['reason' => $reason]);

        return $invoice;
    }

    private function match(SupplierInvoice $invoice, bool $lock = false): void
    {
        $issues = [];
        $status = 'matched';
        $hasPoLines = false;

        foreach ($invoice->lines()->get() as $line) {
            if ($line->purchase_order_line_id === null) {
                continue;
            }
            $hasPoLines = true;
            $query = PurchaseOrderLine::query()->whereKey($line->purchase_order_line_id);
            $poLine = $lock ? $query->lockForUpdate()->first() : $query->first();

            $wouldInvoice = bcadd((string) $poLine->invoiced_base_quantity, (string) $line->base_quantity, 4);
            if (bccomp($wouldInvoice, (string) $poLine->received_base_quantity, 4) > 0) {
                $issues[] = ['purchase_order_line_id' => $poLine->id, 'issue' => 'quantity_exceeds_received', 'received_base' => (string) $poLine->received_base_quantity, 'invoiced_base_after' => $wouldInvoice];
                $status = 'unmatched';
            }
            if (bccomp((string) $line->unit_price, (string) $poLine->unit_price, 4) !== 0) {
                $issues[] = ['purchase_order_line_id' => $poLine->id, 'issue' => 'price_variance', 'ordered_price' => (string) $poLine->unit_price, 'invoiced_price' => (string) $line->unit_price];
                $status = $status === 'unmatched' ? 'unmatched' : 'variance';
            }
        }

        $invoice->forceFill(['match_status' => $hasPoLines ? $status : 'unmatched', 'match_details' => $hasPoLines ? $issues : [['issue' => 'no_purchase_order_lines']]])->save();
    }
}

<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Events\PaymentConfirmed;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\PaymentAllocation;
use App\Domain\Inventory\Services\DocumentNumberer;
use App\Domain\Parties\Models\Customer;
use App\Domain\Parties\Models\Supplier;
use App\Domain\Platform\Services\AuditLogger;
use App\Domain\Procurement\Models\SupplierInvoice;
use App\Domain\Sales\Models\SalesInvoice;
use App\Support\Exceptions\BusinessRuleViolation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Recorded payments and their allocation to open documents. Allocations
 * lock the document, cannot exceed its open balance or the payment's
 * unallocated amount, and are posted to the ledger in the same transaction.
 *
 * Payments recorded here are back-office records of money that has moved.
 * Electronic payments initiated through a provider (POS, Phase 2.5) are only
 * confirmed by the provider's verified callback, never by the client.
 */
class PaymentService
{
    public function __construct(
        private readonly GeneralLedger $ledger,
        private readonly ChartOfAccounts $chart,
        private readonly DocumentNumberer $numbers,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, string>  $allocations  supplier_invoice id => amount
     */
    public function paySupplier(Supplier $supplier, string $amount, string $method, string $date, array $allocations, ?string $reference = null, ?string $accountId = null, ?string $notes = null): Payment
    {
        if (bccomp($amount, '0', 4) <= 0) {
            throw new BusinessRuleViolation('payment_amount_invalid', 'Payment amount must be positive.');
        }

        return DB::transaction(function () use ($supplier, $amount, $method, $date, $allocations, $reference, $accountId, $notes) {
            $cashAccount = $accountId ?? $this->chart->forPaymentMethod($method);

            $payment = Payment::query()->create([
                'number' => $this->numbers->next('PAY'),
                'direction' => 'outgoing',
                'party_type' => 'supplier',
                'party_id' => $supplier->id,
                'method' => $method,
                'amount' => $amount,
                'currency_code' => $supplier->currency_code,
                'payment_date' => $date,
                'reference' => $reference,
                'status' => 'confirmed',
                'account_id' => $cashAccount,
                'notes' => $notes,
                'created_by' => Auth::id(),
            ]);

            $allocated = $this->allocateToSupplierInvoices($payment, $supplier, $allocations);

            // Unallocated outgoing money is a prepayment to the supplier: it sits as a debit on payables.
            $journal = $this->ledger->post($date, 'payment', $payment->id, "Payment {$payment->number} to {$supplier->name}", [
                ['account' => 'accounts_payable', 'debit' => $amount, 'party_type' => 'supplier', 'party_id' => $supplier->id],
                ['account_id' => $cashAccount, 'credit' => $amount],
            ], $payment->currency_code);

            $payment->forceFill(['allocated_amount' => $allocated, 'journal_entry_id' => $journal->id])->save();
            $this->audit->record('payment.recorded', $payment, new: ['number' => $payment->number, 'amount' => $amount, 'method' => $method, 'allocated' => $allocated]);
            event(new PaymentConfirmed($payment->id, $payment->organization_id));

            return $payment->load('allocations');
        });
    }

    /**
     * Money received from a customer. Unallocated amounts remain a credit on
     * the customer's receivable (an advance) until allocated.
     *
     * @param  array<string, string>  $allocations  sales_invoice id => amount
     */
    public function receiveFromCustomer(Customer $customer, string $amount, string $method, string $date, array $allocations, ?string $reference = null, ?string $accountId = null, ?string $notes = null): Payment
    {
        if (bccomp($amount, '0', 4) <= 0) {
            throw new BusinessRuleViolation('payment_amount_invalid', 'Payment amount must be positive.');
        }

        return DB::transaction(function () use ($customer, $amount, $method, $date, $allocations, $reference, $accountId, $notes) {
            $cashAccount = $accountId ?? $this->chart->forPaymentMethod($method);
            $payment = Payment::query()->create([
                'number' => $this->numbers->next('RCT'), 'direction' => 'incoming', 'party_type' => 'customer', 'party_id' => $customer->id,
                'method' => $method, 'amount' => $amount, 'currency_code' => $customer->currency_code, 'payment_date' => $date,
                'reference' => $reference, 'status' => 'confirmed', 'account_id' => $cashAccount, 'notes' => $notes, 'created_by' => Auth::id(),
            ]);

            $allocated = $this->allocateToSalesInvoices($payment, $customer, $allocations);
            $journal = $this->ledger->post($date, 'payment', $payment->id, "Receipt {$payment->number} from {$customer->name}", [
                ['account_id' => $cashAccount, 'debit' => $amount],
                ['account' => 'accounts_receivable', 'credit' => $amount, 'party_type' => 'customer', 'party_id' => $customer->id],
            ], $payment->currency_code);

            $payment->forceFill(['allocated_amount' => $allocated, 'journal_entry_id' => $journal->id])->save();
            $this->audit->record('payment.received', $payment, new: ['number' => $payment->number, 'amount' => $amount, 'method' => $method, 'allocated' => $allocated]);
            event(new PaymentConfirmed($payment->id, $payment->organization_id));

            return $payment->load('allocations');
        });
    }

    /** Allocate an existing payment's unallocated balance. */
    public function allocate(Payment $payment, array $allocations): Payment
    {
        return DB::transaction(function () use ($payment, $allocations) {
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            $added = $locked->party_type === 'supplier'
                ? $this->allocateToSupplierInvoices($locked, Supplier::query()->findOrFail($locked->party_id), $allocations)
                : $this->allocateToSalesInvoices($locked, Customer::query()->findOrFail($locked->party_id), $allocations);
            $locked->forceFill(['allocated_amount' => bcadd((string) $locked->allocated_amount, $added, 4)])->save();
            $this->audit->record('payment.allocated', $locked, new: ['allocations' => $allocations]);

            return $locked->load('allocations');
        });
    }

    private function allocateToSalesInvoices(Payment $payment, Customer $customer, array $allocations): string
    {
        $total = '0';
        $available = bcsub((string) $payment->amount, (string) $payment->allocated_amount, 4);

        foreach ($allocations as $invoiceId => $amount) {
            $amount = (string) $amount;
            $invoice = SalesInvoice::query()->lockForUpdate()->find($invoiceId);
            if ($invoice === null || $invoice->customer_id !== $customer->id || $invoice->status !== 'issued') {
                throw new BusinessRuleViolation('allocation_document_invalid', 'Issued invoice not found for this customer.', 422, ['document_id' => $invoiceId]);
            }
            if ($invoice->currency_code !== $payment->currency_code) {
                throw new BusinessRuleViolation('allocation_currency_mismatch', 'Payment and invoice currencies differ.', 422);
            }
            $open = $invoice->openBalance();
            if (bccomp($amount, '0', 4) <= 0 || bccomp($amount, $open, 4) > 0) {
                throw new BusinessRuleViolation('allocation_exceeds_balance', "Invoice {$invoice->number} has {$open} open.", 422, ['document_id' => $invoiceId, 'open' => $open]);
            }
            $total = bcadd($total, $amount, 4);
            if (bccomp($total, $available, 4) > 0) {
                throw new BusinessRuleViolation('allocation_exceeds_payment', 'Allocations exceed the payment amount.', 422);
            }

            PaymentAllocation::query()->create(['payment_id' => $payment->id, 'document_type' => 'sales_invoice', 'document_id' => $invoice->id, 'amount' => $amount]);
            $invoice->forceFill(['amount_paid' => bcadd((string) $invoice->amount_paid, $amount, 4)])->save();
            $invoice->forceFill(['payment_status' => $invoice->settlementStatus()])->save();
        }

        return $total;
    }

    private function allocateToSupplierInvoices(Payment $payment, Supplier $supplier, array $allocations): string
    {
        $total = '0';
        $available = bcsub((string) $payment->amount, (string) $payment->allocated_amount, 4);

        foreach ($allocations as $invoiceId => $amount) {
            $amount = (string) $amount;
            $invoice = SupplierInvoice::query()->lockForUpdate()->find($invoiceId);
            if ($invoice === null || $invoice->supplier_id !== $supplier->id) {
                throw new BusinessRuleViolation('allocation_document_invalid', 'Invoice not found for this supplier.', 422, ['document_id' => $invoiceId]);
            }
            if ($invoice->status !== 'approved') {
                throw new BusinessRuleViolation('allocation_document_invalid', "Invoice {$invoice->number} is {$invoice->status}; only approved invoices can be paid.", 409);
            }
            if ($invoice->currency_code !== $payment->currency_code) {
                throw new BusinessRuleViolation('allocation_currency_mismatch', 'Payment and invoice currencies differ.', 422);
            }

            $open = bcsub((string) $invoice->total, (string) $invoice->amount_paid, 4);
            if (bccomp($amount, '0', 4) <= 0 || bccomp($amount, $open, 4) > 0) {
                throw new BusinessRuleViolation('allocation_exceeds_balance', "Invoice {$invoice->number} has {$open} open.", 422, ['document_id' => $invoiceId, 'open' => $open]);
            }
            $total = bcadd($total, $amount, 4);
            if (bccomp($total, $available, 4) > 0) {
                throw new BusinessRuleViolation('allocation_exceeds_payment', 'Allocations exceed the payment amount.', 422);
            }

            PaymentAllocation::query()->create(['payment_id' => $payment->id, 'document_type' => 'supplier_invoice', 'document_id' => $invoice->id, 'amount' => $amount]);
            $paid = bcadd((string) $invoice->amount_paid, $amount, 4);
            $invoice->forceFill(['amount_paid' => $paid, 'payment_status' => bccomp($paid, (string) $invoice->total, 4) >= 0 ? 'paid' : 'partial'])->save();
        }

        return $total;
    }
}

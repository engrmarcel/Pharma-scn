<?php

namespace App\Domain\Pos\Services;

use App\Domain\Finance\Services\PaymentService;
use App\Domain\Inventory\Services\DocumentNumberer;
use App\Domain\Parties\Models\Customer;
use App\Domain\Platform\Services\AuditLogger;
use App\Domain\Pos\Models\PosRefund;
use App\Domain\Pos\Models\PosSale;
use App\Domain\Pos\Models\PosSaleLine;
use App\Domain\Pos\Models\PosShift;
use App\Domain\Sales\Models\SalesInvoice;
use App\Domain\Sales\Services\FulfilmentService;
use App\Models\User;
use App\Support\Exceptions\BusinessRuleViolation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Returns and refunds on completed POS sales (spec §11.1, §29.2 #2).
 * A cashier requests; a different user with pos.refund.approve decides.
 * Approval issues a credit note (returned goods quarantined unless the
 * approver may restock a sellable batch) and pays the money back, from the
 * terminal's open cash drawer for cash refunds.
 */
class PosRefundService
{
    public function __construct(
        private readonly FulfilmentService $fulfilment,
        private readonly PaymentService $payments,
        private readonly PosSessionService $sessions,
        private readonly DocumentNumberer $numbers,
        private readonly AuditLogger $audit,
    ) {}

    /** @param list<array{pos_sale_line_id: string, quantity: string, restock_state?: ?string}> $lines */
    public function request(PosSale $sale, array $lines, string $reason, string $refundMethod): PosRefund
    {
        if ($sale->status !== 'completed') {
            throw new BusinessRuleViolation('pos_sale_not_completed', 'Only completed sales can be refunded; cancel unfinished sales instead.', 409);
        }
        $known = $sale->lines()->pluck('id')->all();
        foreach ($lines as $i => $line) {
            if (! in_array($line['pos_sale_line_id'], $known, true)) {
                throw new BusinessRuleViolation('pos_sale_line_unknown', 'Line does not belong to this sale.', 422, ['line' => $i]);
            }
        }

        $refund = DB::transaction(fn () => PosRefund::query()->create([
            'number' => $this->numbers->next('RTN'), 'pos_sale_id' => $sale->id, 'status' => 'pending_approval',
            'reason' => $reason, 'refund_method' => $refundMethod, 'lines' => $lines, 'requested_by' => Auth::id(),
        ]));
        $this->audit->record('pos_refund.requested', $refund, new: ['sale' => $sale->number, 'lines' => count($lines), 'reason' => $reason]);

        return $refund;
    }

    public function decide(PosRefund $refund, bool $approve, User $approver, ?string $notes = null): PosRefund
    {
        return DB::transaction(function () use ($refund, $approve, $approver, $notes) {
            $locked = PosRefund::query()->lockForUpdate()->findOrFail($refund->id);
            if ($locked->status !== 'pending_approval') {
                throw new BusinessRuleViolation('pos_refund_state_invalid', "Refund is {$locked->status}.", 409);
            }
            if ($locked->requested_by === $approver->id) {
                throw new BusinessRuleViolation('self_approval_not_allowed', 'A refund must be approved by someone other than the requester.', 403);
            }

            if (! $approve) {
                if (blank($notes)) {
                    throw new BusinessRuleViolation('decision_notes_required', 'Give a reason when rejecting.');
                }
                $locked->forceFill(['status' => 'rejected', 'decided_by' => $approver->id, 'decided_at' => now(), 'decision_notes' => $notes])->save();
                $this->audit->record('pos_refund.rejected', $locked, new: ['notes' => $notes]);

                return $locked;
            }

            $sale = PosSale::query()->findOrFail($locked->pos_sale_id);
            $invoice = SalesInvoice::query()->findOrFail($sale->sales_invoice_id);
            $creditLines = [];
            foreach ($locked->lines as $line) {
                $posLine = PosSaleLine::query()->with('allocations')->findOrFail($line['pos_sale_line_id']);
                // Return the units to the batch they came from (latest allocation first for partial returns).
                $remaining = bcmul((string) $line['quantity'], bcdiv((string) $posLine->base_quantity, (string) $posLine->quantity, 8), 4);
                foreach ($posLine->allocations->sortByDesc('id') as $allocation) {
                    if (bccomp($remaining, '0', 4) <= 0) {
                        break;
                    }
                    $take = bccomp((string) $allocation->base_quantity, $remaining, 4) < 0 ? (string) $allocation->base_quantity : $remaining;
                    $creditLines[] = [
                        'sales_invoice_line_id' => $posLine->sales_invoice_line_id,
                        'quantity' => bcdiv($take, bcdiv((string) $posLine->base_quantity, (string) $posLine->quantity, 8), 4),
                        'batch_id' => $allocation->batch_id,
                        'restock_state' => $line['restock_state'] ?? 'quarantined',
                    ];
                    $remaining = bcsub($remaining, $take, 4);
                }
            }

            $note = $this->fulfilment->creditNote($invoice, 'return', $creditLines, $sale->warehouse_id, "POS refund {$locked->number}: {$locked->reason}", allowCreditBalance: true);
            $customer = Customer::query()->findOrFail($invoice->customer_id);

            if ($locked->refund_method === 'cash') {
                $shift = PosShift::query()->where('pos_terminal_id', $sale->pos_terminal_id)->where('status', 'open')->first()
                    ?? throw new BusinessRuleViolation('no_open_drawer', 'Open a shift on this terminal to pay a cash refund, or refund by bank transfer.', 409);
                if (bccomp($this->sessions->drawer($shift), (string) $note->total, 4) < 0) {
                    throw new BusinessRuleViolation('drawer_insufficient', 'The drawer does not hold enough cash for this refund.', 409);
                }
                $this->sessions->cash($shift, 'refund', bcmul((string) $note->total, '-1', 4), "Refund {$locked->number}", $sale->id);
                $locked->pos_shift_id = $shift->id;
            }
            $this->payments->refundCustomer($customer, (string) $note->total, $locked->refund_method === 'cash' ? 'cash' : 'bank_transfer', business_today()->toDateString(), $locked->number, "POS refund for {$sale->number}");

            $locked->forceFill(['status' => 'approved', 'amount' => $note->total, 'credit_note_id' => $note->id, 'decided_by' => $approver->id, 'decided_at' => now(), 'decision_notes' => $notes])->save();
            $this->audit->record('pos_refund.approved', $locked, new: ['credit_note' => $note->number, 'amount' => (string) $note->total]);

            return $locked;
        });
    }
}

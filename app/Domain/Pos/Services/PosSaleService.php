<?php

namespace App\Domain\Pos\Services;

use App\Domain\Catalogue\Services\UnitConverter;
use App\Domain\Finance\Services\AccountingBridge;
use App\Domain\Finance\Services\PaymentService;
use App\Domain\Inventory\Models\Batch;
use App\Domain\Inventory\Services\DocumentNumberer;
use App\Domain\Inventory\Services\FefoAllocator;
use App\Domain\Inventory\Services\ReservationService;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\Warehouse;
use App\Domain\Parties\Models\Customer;
use App\Domain\Platform\Services\AuditLogger;
use App\Domain\Pos\Models\PaymentProviderEvent;
use App\Domain\Pos\Models\PosPayment;
use App\Domain\Pos\Models\PosSale;
use App\Domain\Pos\Models\PosSaleAllocation;
use App\Domain\Pos\Models\PosSaleLine;
use App\Domain\Pos\Models\PosShift;
use App\Domain\Pos\Models\PosTerminal;
use App\Domain\Pos\Payments\PaymentProviderRegistry;
use App\Domain\Pos\Payments\ProviderEvent;
use App\Domain\Sales\Services\FulfilmentService;
use App\Domain\Sales\Services\SalesPolicy;
use App\Domain\Sales\Services\SalesPricing;
use App\Models\User;
use App\Support\Exceptions\BusinessRuleViolation;
use App\Support\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * POS sale lifecycle (spec §11.1, §11.3, §11.4):
 *
 *   open ⇄ held ; open → checkout → awaiting_payment (stock reserved) → completed
 *   open / held / awaiting_payment → cancelled (reservations released, cash returned)
 *
 * Completion is one transaction: reservations become sale movements (batch
 * evidence recorded), cost of goods sold and the invoice are posted, and each
 * confirmed POS payment becomes a finance receipt allocated to the invoice.
 * A sale is only completed when confirmed payments cover the total; cash is
 * confirmed at the till, electronic payments only by a verified provider
 * callback or a supervisor's settlement check.
 */
class PosSaleService
{
    public const ELECTRONIC = ['card', 'bank_transfer', 'mobile_money'];

    public function __construct(
        private readonly SalesPricing $pricing,
        private readonly SalesPolicy $salesPolicy,
        private readonly UnitConverter $units,
        private readonly PrescriptionGuard $prescriptions,
        private readonly FefoAllocator $fefo,
        private readonly ReservationService $reservations,
        private readonly FulfilmentService $fulfilment,
        private readonly AccountingBridge $accounting,
        private readonly PaymentService $payments,
        private readonly PosSessionService $sessions,
        private readonly PaymentProviderRegistry $providers,
        private readonly DocumentNumberer $numbers,
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenant,
    ) {}

    /** Idempotent on the client transaction id: a retried start returns the same sale. */
    public function start(PosShift $shift, User $cashier, string $clientTransactionId, ?Customer $customer = null): PosSale
    {
        $existing = PosSale::query()->where('client_transaction_id', strtolower($clientTransactionId))->first();
        if ($existing !== null) {
            return $existing->load('lines', 'payments');
        }
        $this->sessions->assertOpen($shift);
        if ($shift->cashier_id !== $cashier->id) {
            throw new BusinessRuleViolation('shift_not_yours', 'Sell on your own open shift.', 403);
        }
        $terminal = PosTerminal::query()->findOrFail($shift->pos_terminal_id);

        try {
            return DB::transaction(fn () => PosSale::query()->create([
                'number' => $this->numbers->next('POS'), 'client_transaction_id' => strtolower($clientTransactionId),
                'pos_shift_id' => $shift->id, 'pos_terminal_id' => $terminal->id, 'cashier_id' => $cashier->id,
                'customer_id' => $customer?->id, 'warehouse_id' => $terminal->warehouse_id, 'status' => 'open',
                'currency_code' => $this->tenant->organization()->country->currency_code,
            ]))->load('lines', 'payments');
        } catch (UniqueConstraintViolationException) {
            return PosSale::query()->where('client_transaction_id', strtolower($clientTransactionId))->firstOrFail()->load('lines', 'payments');
        }
    }

    /**
     * Replace the cart.
     *
     * @param  list<array{product_id: string, quantity: string, package_level_code?: ?string, unit_price?: ?string, discount_percent?: ?string, batch_id?: ?string, prescription?: ?array}>  $lines
     */
    public function setLines(PosSale $sale, array $lines): PosSale
    {
        return DB::transaction(function () use ($sale, $lines) {
            $locked = $this->lock($sale, ['open', 'held']);
            $this->writeLines($locked, $lines, today()->toDateString());
            $locked->forceFill(['status' => 'open'])->save();

            return $locked->load('lines', 'payments');
        });
    }

    /**
     * Price the lines and write them. Online, a price, discount or
     * prescription breach is refused. Offline replay passes $issues: the
     * goods and the money have already changed hands, so the same breaches
     * are recorded for review instead (spec §13.3: never discard a sale).
     */
    private function writeLines(PosSale $locked, array $lines, string $date, ?array &$issues = null): void
    {
        $offline = $issues !== null;
        $warehouse = Warehouse::query()->findOrFail($locked->warehouse_id);
        $customer = $locked->customer_id ? Customer::query()->find($locked->customer_id) : null;
        $maxDiscount = $this->salesPolicy->settings()['max_discount_percent'];

        $locked->lines()->delete();
        $totals = ['subtotal' => '0', 'discount' => '0', 'tax' => '0', 'total' => '0'];
        foreach ($lines as $i => $line) {
            $level = $this->units->level($line['product_id'], $line['package_level_code'] ?? null);
            $qty = (string) $line['quantity'];
            $list = $this->listPriceOrNull($customer, $warehouse, $line['product_id'], $level->code, $qty, $date);
            $price = isset($line['unit_price']) ? (string) $line['unit_price'] : ($list ?? throw new BusinessRuleViolation('price_required', 'No price is configured for this product.', 422, ['line' => $i]));
            if (isset($line['unit_price']) && ($list === null || bccomp($price, $list, 4) !== 0) && ! Gate::allows('sales.price.override')) {
                $offline
                    ? $issues[] = ['type' => 'price_variance', 'severity' => 'medium', 'line' => $i, 'product_id' => $line['product_id'], 'details' => ['charged' => bcadd($price, '0', 4), 'list_price' => $list]]
                    : throw new BusinessRuleViolation('price_override_not_allowed', 'Changing the price needs a supervisor.', 403, ['line' => $i]);
            }
            $discount = (string) ($line['discount_percent'] ?? '0');
            if (bccomp($discount, $maxDiscount, 4) > 0 && ! Gate::allows('sales.approve')) {
                $offline
                    ? $issues[] = ['type' => 'discount_over_limit', 'severity' => 'medium', 'line' => $i, 'product_id' => $line['product_id'], 'details' => ['discount_percent' => $discount, 'limit' => $maxDiscount]]
                    : throw new BusinessRuleViolation('discount_not_allowed', "Discounts above {$maxDiscount}% need a supervisor.", 403, ['line' => $i]);
            }

            $batchId = $line['batch_id'] ?? null;
            if ($batchId !== null) {
                $batch = Batch::query()->where('product_id', $line['product_id'])->find($batchId)
                    ?? throw new BusinessRuleViolation('batch_mismatch', 'The scanned batch does not belong to this product.', 422, ['line' => $i]);
                if (! $offline) {
                    $this->fefo->assertSellable($batch); // offline: judged when stock is allocated (allocateOffline)
                }
            }

            try {
                $prescription = $this->prescriptions->check($line['product_id'], $line['prescription'] ?? null, $i);
            } catch (BusinessRuleViolation $e) {
                if (! $offline) {
                    throw $e;
                }
                $prescription = $line['prescription'] ?? null;
                $issues[] = ['type' => 'prescription_control', 'severity' => 'high', 'line' => $i, 'product_id' => $line['product_id'], 'details' => ['code' => $e->errorCode, 'message' => $e->getMessage()]];
            }
            $tax = $this->pricing->tax($line['product_id'], $date);
            $amounts = SalesPricing::amounts($qty, $price, $discount, $tax['rate']);

            $locked->lines()->create([
                'product_id' => $line['product_id'], 'package_level_code' => $level->code, 'quantity' => $qty,
                'base_quantity' => bcmul($qty, (string) $level->base_unit_quantity, 4), 'unit_price' => $price, 'list_price' => $list,
                'discount_percent' => $discount, 'tax_category_code' => $tax['category'], 'tax_rate' => $tax['rate'],
                'line_subtotal' => $amounts['subtotal'], 'line_discount' => $amounts['discount'], 'line_tax' => $amounts['tax'],
                'line_total' => $amounts['total'], 'requested_batch_id' => $batchId, 'prescription' => $prescription,
            ]);
            foreach (['subtotal', 'discount', 'tax', 'total'] as $k) {
                $totals[$k] = Money::add($totals[$k], $amounts[$k]);
            }
        }

        $locked->forceFill(['subtotal' => $totals['subtotal'], 'discount_total' => $totals['discount'], 'tax_total' => $totals['tax'], 'total' => $totals['total']])->save();
    }

    /**
     * Replay a sale made while the till was offline (spec §13.1, §13.4, §13.5).
     *
     * The sale is recorded as it happened: the device's prices (variances are
     * raised for review), the cash it took (confirmed, marked recorded_offline:
     * in the drawer but reconciled only when the shift is counted), and the
     * stock it handed over. Stock is allocated under the same row locks as
     * online sales; whatever the server cannot find becomes a stock_shortfall
     * issue instead of a blind negative balance. Card and transfer payments
     * taken on a standalone terminal stay pending until a supervisor confirms them.
     *
     * @param  array{lines: list<array>, payments: list<array>, completed_at: string, customer_id?: ?string}  $data
     * @return array{sale: PosSale, issues: list<array>, replayed: bool}
     */
    public function recordOffline(PosShift $shift, User $cashier, string $clientTransactionId, array $data, ?string $deviceId = null): array
    {
        $existing = PosSale::query()->where('client_transaction_id', strtolower($clientTransactionId))->first();
        if ($existing !== null) {
            return ['sale' => $existing->load('lines', 'payments'), 'issues' => [], 'replayed' => true];
        }
        if ($shift->cashier_id !== $cashier->id) {
            throw new BusinessRuleViolation('shift_not_yours', 'The sale belongs to another cashier\'s shift.', 403);
        }
        if ($shift->status === 'reviewed') {
            throw new BusinessRuleViolation('shift_reviewed', 'The shift was already counted and reviewed; a supervisor must handle this sale.', 409);
        }

        return DB::transaction(function () use ($shift, $cashier, $clientTransactionId, $data, $deviceId) {
            $issues = [];
            $terminal = PosTerminal::query()->findOrFail($shift->pos_terminal_id);
            $completedAt = Carbon::parse($data['completed_at']);

            $sale = PosSale::query()->create([
                'number' => $this->numbers->next('POS'), 'client_transaction_id' => strtolower($clientTransactionId),
                'pos_shift_id' => $shift->id, 'pos_terminal_id' => $terminal->id, 'cashier_id' => $cashier->id,
                'customer_id' => $data['customer_id'] ?? null, 'warehouse_id' => $terminal->warehouse_id, 'status' => 'open',
                'currency_code' => $this->tenant->organization()->country->currency_code,
                'captured_offline' => true, 'client_completed_at' => $completedAt, 'device_id' => $deviceId,
            ]);
            $this->writeLines($sale, $data['lines'], $completedAt->toDateString(), $issues);

            foreach ($sale->lines()->orderBy('id')->get() as $i => $line) {
                $this->allocateOffline($sale, $line, $i, $issues);
            }
            $sale->forceFill(['status' => 'awaiting_payment'])->save();

            $cashTotal = '0';
            foreach ($data['payments'] as $p) {
                if ($p['method'] === 'cash') {
                    $tendered = (string) ($p['tendered'] ?? $p['amount']);
                    $sale->payments()->create(['method' => 'cash', 'channel' => 'cash', 'amount' => $p['amount'], 'tendered' => $tendered,
                        'status' => 'confirmed', 'confirmed_at' => $completedAt, 'recorded_offline' => true]);
                    $this->sessions->cash($shift, 'sale', (string) $p['amount'], "Offline sale {$sale->number}", $sale->id);
                    $sale->forceFill(['change_due' => bcadd((string) $sale->change_due, bcsub($tendered, (string) $p['amount'], 4), 4)])->save();
                    $cashTotal = bcadd($cashTotal, (string) $p['amount'], 4);
                } else {
                    // A standalone terminal cannot be verified from the device: pending until a supervisor checks settlement.
                    $sale->payments()->create(['method' => $p['method'], 'channel' => 'manual_terminal', 'amount' => $p['amount'], 'status' => 'pending',
                        'external_approval_code' => $p['approval_code'] ?? null, 'recorded_offline' => true]);
                }
            }

            $committed = $this->committed($sale);
            if (bccomp($committed, (string) $sale->total, 4) < 0) {
                $issues[] = ['type' => 'payment_shortfall', 'severity' => 'high', 'details' => ['total' => (string) $sale->total, 'recorded_payments' => $committed]];
            }
            if ($shift->status === 'closed' && bccomp($cashTotal, '0', 4) > 0) {
                $issues[] = ['type' => 'late_offline_sale', 'severity' => 'medium', 'details' => ['shift' => $shift->number, 'cash' => $cashTotal,
                    'note' => 'The shift was closed before this sale synced; its expected cash no longer includes this amount.']];
            }

            $this->audit->record('pos_sale.recorded_offline', $sale, new: ['number' => $sale->number, 'total' => (string) $sale->total, 'issues' => array_column($issues, 'type')]);
            $this->tryComplete($sale->fresh());

            return ['sale' => $sale->fresh(['lines', 'payments']), 'issues' => $issues, 'replayed' => false];
        });
    }

    /**
     * Reserve what the server can find for an offline line. A scanned batch is
     * honoured (it is what physically left), even if it has since been blocked;
     * anything that cannot be found is left unallocated and raised as a shortfall.
     */
    private function allocateOffline(PosSale $sale, PosSaleLine $line, int $index, array &$issues): void
    {
        $base = (string) $line->base_quantity;
        $allocated = '0';

        if ($line->requested_batch_id) {
            $batch = Batch::query()->findOrFail($line->requested_batch_id);
            try {
                $this->fefo->assertSellable($batch);
            } catch (BusinessRuleViolation $e) {
                $issues[] = ['type' => 'blocked_batch_sold', 'severity' => 'high', 'line' => $index, 'product_id' => $line->product_id,
                    'details' => ['batch_id' => $batch->id, 'batch_number' => $batch->batch_number, 'reason' => $e->details['reason'] ?? $e->getMessage()]];
            }
            $free = (string) (DB::table('inventory_balances')->where('warehouse_id', $sale->warehouse_id)->where('batch_id', $batch->id)
                ->where('stock_state', 'available')->lockForUpdate()->selectRaw('COALESCE(SUM(quantity - reserved_quantity), 0) as free')->value('free') ?? '0');
            $allocated = bccomp($free, $base, 4) >= 0 ? $base : (bccomp($free, '0', 4) > 0 ? $free : '0');
            if (bccomp($allocated, '0', 4) > 0) {
                $this->reservations->reserveBatch($line->product_id, $sale->warehouse_id, $batch->id, $allocated, 'pos_sale_line', $line->id);
            }
        } else {
            try {
                $this->reservations->reserve($line->product_id, $sale->warehouse_id, $base, 'pos_sale_line', $line->id);
                $allocated = $base;
            } catch (BusinessRuleViolation $e) {
                if ($e->errorCode !== 'insufficient_stock') {
                    throw $e;
                }
                $sellable = (string) ($e->details['sellable'] ?? '0');
                if (bccomp($sellable, '0', 4) > 0) {
                    $this->reservations->reserve($line->product_id, $sale->warehouse_id, $sellable, 'pos_sale_line', $line->id);
                    $allocated = $sellable;
                }
            }
        }

        $short = bcsub($base, $allocated, 4);
        if (bccomp($short, '0', 4) > 0) {
            $line->forceFill(['unallocated_base_quantity' => $short])->save();
            $issues[] = ['type' => 'stock_shortfall', 'severity' => 'high', 'line' => $index, 'product_id' => $line->product_id,
                'warehouse_id' => $sale->warehouse_id, 'quantity' => $short, 'subject_type' => 'pos_sale_line', 'subject_id' => $line->id,
                'details' => ['sold' => $base, 'issued' => $allocated, 'sale' => $sale->number]];
        }
    }

    public function hold(PosSale $sale): PosSale
    {
        return DB::transaction(function () use ($sale) {
            $locked = $this->lock($sale, ['open']);
            $locked->forceFill(['status' => 'held'])->save();

            return $locked->load('lines', 'payments');
        });
    }

    /** Reserve stock (scanned batch or FEFO) and start taking payment. */
    public function checkout(PosSale $sale): PosSale
    {
        return DB::transaction(function () use ($sale) {
            $locked = $this->lock($sale, ['open']);
            $this->sessions->assertOpen(PosShift::query()->findOrFail($locked->pos_shift_id));
            if ($locked->lines()->count() === 0) {
                throw new BusinessRuleViolation('pos_sale_empty', 'The cart is empty.', 422);
            }

            foreach ($locked->lines()->get() as $line) {
                if ($line->requested_batch_id) {
                    $this->fefo->assertSellable(Batch::query()->findOrFail($line->requested_batch_id));
                    $this->reservations->reserveBatch($line->product_id, $locked->warehouse_id, $line->requested_batch_id, (string) $line->base_quantity, 'pos_sale_line', $line->id);
                } else {
                    $this->reservations->reserve($line->product_id, $locked->warehouse_id, (string) $line->base_quantity, 'pos_sale_line', $line->id);
                }
            }
            $locked->forceFill(['status' => 'awaiting_payment'])->save();

            return $locked->load('lines', 'payments');
        });
    }

    /**
     * @return array{sale: PosSale, payment: PosPayment, instructions?: array}
     */
    public function addPayment(PosSale $sale, string $method, string $amount, string $channel, ?string $tendered = null, ?string $approvalCode = null): array
    {
        return DB::transaction(function () use ($sale, $method, $amount, $channel, $tendered, $approvalCode) {
            $locked = $this->lock($sale, ['awaiting_payment']);
            if (bccomp($amount, '0', 4) <= 0) {
                throw new BusinessRuleViolation('payment_amount_invalid', 'Amount must be positive.');
            }
            $outstanding = bcsub((string) $locked->total, $this->committed($locked), 4);
            if (bccomp($amount, $outstanding, 4) > 0) {
                throw new BusinessRuleViolation('payment_exceeds_balance', "Only {$outstanding} is outstanding.", 422, ['outstanding' => $outstanding]);
            }

            $instructions = null;
            if ($method === 'cash') {
                $tendered ??= $amount;
                if (bccomp($tendered, $amount, 4) < 0) {
                    throw new BusinessRuleViolation('cash_tendered_insufficient', 'Cash tendered is less than the amount.', 422);
                }
                $payment = $locked->payments()->create(['method' => 'cash', 'channel' => 'cash', 'amount' => $amount, 'tendered' => $tendered, 'status' => 'confirmed', 'confirmed_at' => now()]);
                $this->sessions->cash(PosShift::query()->findOrFail($locked->pos_shift_id), 'sale', $amount, "Sale {$locked->number}", $locked->id);
                $locked->forceFill(['change_due' => bcadd((string) $locked->change_due, bcsub($tendered, $amount, 4), 4)])->save();
            } elseif ($channel === 'manual_terminal') {
                if (blank($approvalCode)) {
                    throw new BusinessRuleViolation('approval_code_required', 'Enter the approval code printed by the card or transfer terminal.', 422);
                }
                // A standalone terminal cannot be verified from here: the payment waits for a supervisor's settlement check.
                $payment = $locked->payments()->create(['method' => $method, 'channel' => 'manual_terminal', 'amount' => $amount, 'status' => 'pending', 'external_approval_code' => $approvalCode]);
            } else {
                $provider = $this->providers->get($channel);
                $payment = $locked->payments()->create(['method' => $method, 'channel' => $provider->code(), 'amount' => $amount, 'status' => 'pending']);
                $intent = $provider->initiate($payment, $locked->currency_code);
                $payment->forceFill(['provider_reference' => $intent['reference']])->save();
                $instructions = $intent['instructions'] + ['reference' => $intent['reference']];
            }

            $this->audit->record('pos_payment.recorded', $locked, new: ['payment_id' => $payment->id, 'method' => $method, 'channel' => $payment->channel, 'amount' => $amount, 'status' => $payment->status]);
            $this->tryComplete($locked);

            return array_filter(['sale' => $locked->fresh(['lines', 'payments']), 'payment' => $payment->fresh(), 'instructions' => $instructions]);
        });
    }

    /** Supervisor confirms a standalone-terminal payment after checking the terminal's settlement. */
    public function confirmManualPayment(PosPayment $payment, User $supervisor): PosSale
    {
        return DB::transaction(function () use ($payment, $supervisor) {
            $locked = PosPayment::query()->lockForUpdate()->findOrFail($payment->id);
            $sale = PosSale::query()->findOrFail($locked->pos_sale_id);
            if ($locked->channel !== 'manual_terminal' || $locked->status !== 'pending') {
                throw new BusinessRuleViolation('payment_not_confirmable', 'Only pending standalone-terminal payments can be confirmed here.', 409);
            }
            if ($sale->cashier_id === $supervisor->id) {
                throw new BusinessRuleViolation('self_confirmation_not_allowed', 'Another person must confirm a payment the cashier recorded.', 403);
            }

            $locked->forceFill(['status' => 'confirmed', 'confirmed_by' => $supervisor->id, 'confirmed_at' => now()])->save();
            $this->audit->record('pos_payment.confirmed', $sale, new: ['payment_id' => $locked->id, 'approval_code' => $locked->external_approval_code]);
            $this->tryComplete($sale);

            return $sale->fresh(['lines', 'payments']);
        });
    }

    /**
     * Apply a verified provider callback. Duplicate events are recorded once and
     * have no further effect; amounts and currency must match the payment.
     */
    public function handleProviderEvent(string $provider, ProviderEvent $event): string
    {
        try {
            $record = PaymentProviderEvent::query()->create([
                'provider' => $provider, 'event_id' => $event->eventId, 'reference' => $event->reference, 'event_type' => $event->type, 'payload' => $event->payload,
            ]);
        } catch (UniqueConstraintViolationException) {
            return 'duplicate';
        }

        $payment = PosPayment::query()->where('channel', $provider)->where('provider_reference', $event->reference)->first();
        if ($payment === null) {
            $record->forceFill(['outcome' => 'unknown_reference'])->save();

            return 'unknown_reference';
        }

        $sale = PosSale::acrossTenants()->findOrFail($payment->pos_sale_id);
        $organization = Organization::query()->findOrFail($sale->organization_id);

        $outcome = $this->tenant->runAs($organization, fn () => DB::transaction(function () use ($payment, $sale, $event) {
            $locked = PosPayment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($locked->status !== 'pending') {
                return 'already_'.$locked->status;
            }
            if ($event->type === 'payment.succeeded') {
                if (bccomp($event->amount, (string) $locked->amount, 4) !== 0 || strtoupper($event->currency) !== $sale->currency_code) {
                    $locked->forceFill(['status' => 'failed'])->save();
                    $this->audit->record('pos_payment.provider_mismatch', $sale, new: ['payment_id' => $locked->id, 'event_amount' => $event->amount, 'event_currency' => $event->currency]);

                    return 'amount_mismatch';
                }
                $locked->forceFill(['status' => 'confirmed', 'confirmed_at' => now()])->save();
                $this->audit->record('pos_payment.confirmed', $sale, new: ['payment_id' => $locked->id, 'provider_event' => $event->eventId]);
                $this->tryComplete(PosSale::query()->findOrFail($sale->id));

                return 'confirmed';
            }
            $locked->forceFill(['status' => 'failed'])->save();
            $this->audit->record('pos_payment.failed', $sale, new: ['payment_id' => $locked->id, 'provider_event' => $event->eventId]);

            return 'failed';
        }));

        $record->forceFill(['outcome' => $outcome])->save();

        return $outcome;
    }

    public function cancel(PosSale $sale, string $reason): PosSale
    {
        return DB::transaction(function () use ($sale, $reason) {
            $locked = $this->lock($sale, ['open', 'held', 'awaiting_payment']);
            if ($locked->payments()->where('status', 'confirmed')->whereIn('method', self::ELECTRONIC)->exists()) {
                throw new BusinessRuleViolation('electronic_payment_confirmed', 'An electronic payment was already confirmed; complete the sale and refund it instead.', 409);
            }

            $cash = (string) $locked->payments()->where('status', 'confirmed')->where('method', 'cash')->sum('amount');
            if (bccomp($cash, '0', 4) > 0) {
                $this->sessions->cash(PosShift::query()->findOrFail($locked->pos_shift_id), 'refund', bcmul($cash, '-1', 4), "Cancelled sale {$locked->number}", $locked->id);
            }
            $locked->payments()->whereIn('status', ['pending', 'confirmed'])->update(['status' => 'cancelled', 'updated_at' => now()]);
            foreach ($locked->lines()->get() as $line) {
                $this->reservations->release('pos_sale_line', $line->id);
            }
            $locked->forceFill(['status' => 'cancelled', 'cancel_reason' => $reason])->save();
            $this->audit->record('pos_sale.cancelled', $locked, new: ['reason' => $reason, 'cash_returned' => $cash]);

            return $locked->load('lines', 'payments');
        });
    }

    private function tryComplete(PosSale $sale): void
    {
        $confirmed = (string) $sale->payments()->where('status', 'confirmed')->sum('amount');
        if ($sale->status === 'awaiting_payment' && bccomp($confirmed, (string) $sale->total, 4) >= 0) {
            $this->complete($sale);
        }
    }

    private function complete(PosSale $sale): void
    {
        $customer = $sale->customer_id ? Customer::query()->findOrFail($sale->customer_id) : $this->walkInCustomer();
        $invoiceLines = [];
        $deltas = [];

        foreach ($sale->lines()->orderBy('id')->get() as $line) {
            // Offline shortfalls (unallocated) are issued later, when the issue is resolved.
            $toShip = bcsub((string) $line->base_quantity, (string) $line->unallocated_base_quantity, 4);
            $shipped = bccomp($toShip, '0', 4) > 0 ? $this->reservations->consumeQuantity('pos_sale_line', $line->id, $toShip) : ['batches' => []];
            $costs = Batch::query()->whereIn('id', array_keys($shipped['batches']))->pluck('unit_cost', 'id');
            foreach ($shipped['batches'] as $batchId => $qty) {
                PosSaleAllocation::query()->create(['pos_sale_line_id' => $line->id, 'batch_id' => $batchId, 'base_quantity' => $qty, 'unit_cost' => $costs[$batchId]]);
                $deltas[] = ['batch_id' => $batchId, 'quantity' => $qty];
            }
            $invoiceLines[] = $line->only(['product_id', 'package_level_code', 'quantity', 'base_quantity', 'unit_price', 'list_price', 'discount_percent',
                'tax_category_code', 'tax_rate', 'line_subtotal', 'line_discount', 'line_tax', 'line_total']);
        }

        $this->accounting->deliveryCost('pos_sale', $sale->id, "POS sale {$sale->number} (cost of goods sold)", $deltas, today()->toDateString());
        $invoice = $this->fulfilment->issueDirectInvoice($customer->id, $sale->warehouse_id, 'pos', $sale->currency_code, $invoiceLines);
        foreach ($sale->lines()->orderBy('id')->get()->values() as $i => $line) {
            $line->forceFill(['sales_invoice_line_id' => $invoice->lines[$i]->id])->save();
        }

        foreach ($sale->payments()->where('status', 'confirmed')->get() as $payment) {
            $receipt = $this->payments->receiveFromCustomer($customer, (string) $payment->amount, $payment->method, today()->toDateString(),
                [$invoice->id => (string) $payment->amount], $payment->provider_reference ?? $payment->external_approval_code, null, "POS sale {$sale->number}");
            $payment->forceFill(['finance_payment_id' => $receipt->id])->save();
        }

        $sale->forceFill(['status' => 'completed', 'sales_invoice_id' => $invoice->id, 'completed_at' => now(), 'customer_id' => $customer->id])->save();
        $this->audit->record('pos_sale.completed', $sale, new: ['number' => $sale->number, 'invoice' => $invoice->number, 'total' => (string) $sale->total]);
    }

    public function walkInCustomer(): Customer
    {
        return Customer::query()->firstOrCreate(['code' => 'WALK-IN'], [
            'name' => 'Walk-in customer', 'type' => 'individual', 'currency_code' => $this->tenant->organization()->country->currency_code,
        ]);
    }

    /** Confirmed plus pending payments: money already committed against the sale. */
    private function committed(PosSale $sale): string
    {
        return bcadd((string) $sale->payments()->whereIn('status', ['confirmed', 'pending'])->sum('amount'), '0', 4);
    }

    private function lock(PosSale $sale, array $statuses): PosSale
    {
        $locked = PosSale::query()->lockForUpdate()->findOrFail($sale->id);
        if (! in_array($locked->status, $statuses, true)) {
            throw new BusinessRuleViolation('pos_sale_state_invalid', "Sale is {$locked->status}.", 409);
        }

        return $locked;
    }

    private function listPriceOrNull(?Customer $customer, $warehouse, string $productId, string $level, string $qty, string $date): ?string
    {
        try {
            return $this->pricing->listPrice($customer, $warehouse, $productId, $level, $qty, $date)['price'];
        } catch (BusinessRuleViolation $e) {
            if ($e->errorCode === 'price_required') {
                return null;
            }
            throw $e;
        }
    }
}

<?php

namespace App\Domain\Pos\Services;

use App\Domain\Billing\Services\EntitlementService;
use App\Domain\Inventory\Services\DocumentNumberer;
use App\Domain\Organizations\Models\Warehouse;
use App\Domain\Platform\Services\AuditLogger;
use App\Domain\Pos\Models\PosCashMovement;
use App\Domain\Pos\Models\PosSale;
use App\Domain\Pos\Models\PosShift;
use App\Domain\Pos\Models\PosTerminal;
use App\Models\User;
use App\Support\Exceptions\BusinessRuleViolation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Terminals and cashier shifts (spec §11.2). The drawer is tracked as a
 * running list of signed cash movements; expected cash at close is their
 * sum, so every cash event is explainable.
 */
class PosSessionService
{
    public function __construct(
        private readonly EntitlementService $entitlements,
        private readonly DocumentNumberer $numbers,
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenant,
    ) {}

    public function registerTerminal(Warehouse $warehouse, string $code, string $name, ?string $deviceIdentifier = null): PosTerminal
    {
        $terminal = $this->entitlements->guard($this->tenant->organization(), 'pos_terminals', fn () => PosTerminal::query()->create([
            'branch_id' => $warehouse->branch_id, 'warehouse_id' => $warehouse->id, 'code' => $code, 'name' => $name,
            'status' => 'active', 'device_identifier' => $deviceIdentifier,
        ]));
        $this->audit->record('pos_terminal.registered', $terminal, new: ['code' => $code, 'warehouse_id' => $warehouse->id]);

        return $terminal;
    }

    public function openShift(PosTerminal $terminal, User $cashier, string $openingFloat): PosShift
    {
        if ($terminal->status !== 'active') {
            throw new BusinessRuleViolation('terminal_disabled', 'This terminal is disabled.', 409);
        }

        return DB::transaction(function () use ($terminal, $cashier, $openingFloat) {
            PosTerminal::query()->whereKey($terminal->id)->lockForUpdate()->first();
            if (PosShift::query()->where('pos_terminal_id', $terminal->id)->where('status', 'open')->exists()) {
                throw new BusinessRuleViolation('shift_already_open', 'A shift is already open on this terminal.', 409);
            }
            if (PosShift::query()->where('cashier_id', $cashier->id)->where('status', 'open')->exists()) {
                throw new BusinessRuleViolation('cashier_shift_open', 'You already have an open shift on another terminal.', 409);
            }

            $shift = PosShift::query()->create([
                'number' => $this->numbers->next('SHF'), 'pos_terminal_id' => $terminal->id, 'cashier_id' => $cashier->id,
                'status' => 'open', 'opened_at' => now(), 'opening_float' => $openingFloat,
            ]);
            $this->cash($shift, 'opening_float', $openingFloat, 'Opening float');
            $terminal->forceFill(['last_seen_at' => now()])->save();
            $this->audit->record('pos_shift.opened', $shift, new: ['terminal' => $terminal->code, 'opening_float' => $openingFloat]);

            return $shift;
        });
    }

    /** Cash added to (positive) or taken from (negative) the drawer outside a sale. */
    public function cashMovement(PosShift $shift, string $amount, string $reason): PosCashMovement
    {
        $this->assertOpen($shift);
        if (bccomp($amount, '0', 4) === 0) {
            throw new BusinessRuleViolation('cash_amount_invalid', 'Amount must not be zero.');
        }
        if (bccomp($amount, '0', 4) < 0 && bccomp(bcadd($this->drawer($shift), $amount, 4), '0', 4) < 0) {
            throw new BusinessRuleViolation('drawer_insufficient', 'The drawer does not hold that much cash.', 409);
        }

        $movement = $this->cash($shift, bccomp($amount, '0', 4) > 0 ? 'cash_in' : 'cash_out', $amount, $reason);
        $this->audit->record('pos_shift.cash_movement', $shift, new: ['amount' => $amount, 'reason' => $reason]);

        return $movement;
    }

    public function closeShift(PosShift $shift, string $countedCash, ?string $notes = null): PosShift
    {
        return DB::transaction(function () use ($shift, $countedCash, $notes) {
            $locked = PosShift::query()->lockForUpdate()->findOrFail($shift->id);
            $this->assertOpen($locked);

            $unfinished = PosSale::query()->where('pos_shift_id', $locked->id)->whereIn('status', ['open', 'awaiting_payment'])->count();
            if ($unfinished > 0) {
                throw new BusinessRuleViolation('shift_has_open_sales', "Complete, hold or cancel the {$unfinished} unfinished sale(s) first.", 409);
            }

            $expected = $this->drawer($locked);
            $variance = bcsub($countedCash, $expected, 4);
            $locked->forceFill([
                'status' => 'closed', 'closed_at' => now(), 'expected_cash' => $expected, 'counted_cash' => $countedCash,
                'variance' => $variance, 'closing_notes' => $notes,
            ])->save();
            $this->audit->record('pos_shift.closed', $locked, new: ['expected_cash' => $expected, 'counted_cash' => $countedCash, 'variance' => $variance]);

            return $locked;
        });
    }

    /** A supervisor other than the cashier signs off the closed shift and its variance. */
    public function reviewShift(PosShift $shift, User $reviewer, ?string $notes): PosShift
    {
        if ($shift->status !== 'closed') {
            throw new BusinessRuleViolation('shift_state_invalid', "Shift is {$shift->status}.", 409);
        }
        if ($shift->cashier_id === $reviewer->id) {
            throw new BusinessRuleViolation('self_review_not_allowed', 'A cashier cannot review their own shift.', 403);
        }
        if (bccomp((string) $shift->variance, '0', 4) !== 0 && blank($notes)) {
            throw new BusinessRuleViolation('variance_explanation_required', 'Explain the cash variance before signing off.', 422);
        }

        $shift->forceFill(['status' => 'reviewed', 'reviewed_by' => $reviewer->id, 'reviewed_at' => now(), 'review_notes' => $notes])->save();
        $this->audit->record('pos_shift.reviewed', $shift, new: ['variance' => (string) $shift->variance, 'notes' => $notes]);

        return $shift;
    }

    public function cash(PosShift $shift, string $type, string $amount, ?string $reason = null, ?string $saleId = null): PosCashMovement
    {
        return PosCashMovement::query()->create([
            'pos_shift_id' => $shift->id, 'type' => $type, 'amount' => $amount, 'reason' => $reason,
            'pos_sale_id' => $saleId, 'created_by' => Auth::id(),
        ]);
    }

    public function drawer(PosShift $shift): string
    {
        return bcadd((string) PosCashMovement::query()->where('pos_shift_id', $shift->id)->sum('amount'), '0', 4);
    }

    public function assertOpen(PosShift $shift): void
    {
        if ($shift->status !== 'open') {
            throw new BusinessRuleViolation('shift_not_open', 'The shift is not open.', 409);
        }
    }
}

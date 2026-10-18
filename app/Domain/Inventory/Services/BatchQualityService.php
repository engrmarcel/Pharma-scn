<?php

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Models\Batch;
use App\Domain\Inventory\Models\InventoryBalance;
use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Inventory\Support\StockLine;
use App\Domain\Platform\Services\AuditLogger;
use App\Support\Exceptions\BusinessRuleViolation;
use Illuminate\Support\Facades\DB;

/**
 * Quality status of a batch across all of the organization's warehouses.
 * Quarantine releases open reservations and moves every available unit to
 * the quarantined state, so it can no longer be allocated anywhere.
 */
class BatchQualityService
{
    public function __construct(
        private readonly InventoryLedger $ledger,
        private readonly AuditLogger $audit,
    ) {}

    public function quarantine(Batch $batch, string $reason): Batch
    {
        return $this->changeStatus($batch, 'quarantined', 'available', 'quarantined', $reason);
    }

    /** Release back to sellable stock; refused for expired or recalled batches. */
    public function release(Batch $batch, string $reason): Batch
    {
        if ($batch->isExpired() || $batch->recall_status === 'recalled') {
            throw new BusinessRuleViolation('batch_release_blocked', 'Expired or recalled batches cannot be released for sale.', 409);
        }

        return $this->changeStatus($batch, 'released', 'quarantined', 'available', $reason);
    }

    /** Rejected stock stays quarantined until written off. */
    public function reject(Batch $batch, string $reason): Batch
    {
        return $this->changeStatus($batch, 'rejected', 'available', 'quarantined', $reason);
    }

    private function changeStatus(Batch $batch, string $status, string $fromState, string $toState, string $reason): Batch
    {
        return DB::transaction(function () use ($batch, $status, $fromState, $toState, $reason) {
            $locked = Batch::query()->lockForUpdate()->findOrFail($batch->id);
            $before = $locked->quality_status;
            if ($before === $status) {
                throw new BusinessRuleViolation('batch_status_unchanged', "Batch is already {$status}.", 409);
            }

            $released = 0;
            if ($fromState === 'available') {
                $released = $this->releaseReservations($locked);
            }

            $lines = [];
            foreach (InventoryBalance::query()->where('batch_id', $locked->id)->where('stock_state', $fromState)->where('quantity', '>', 0)->get() as $balance) {
                $lines[] = new StockLine($balance->warehouse_id, $locked->id, $fromState, bcmul((string) $balance->quantity, '-1', 4), 'state_change');
                $lines[] = new StockLine($balance->warehouse_id, $locked->id, $toState, (string) $balance->quantity, 'state_change');
            }

            $locked->forceFill(['quality_status' => $status])->save();
            if ($lines !== []) {
                $this->ledger->post($lines, "quality_{$status}", 'batch', $locked->id);
            }

            $this->audit->record("batch.{$status}", $locked, ['quality_status' => $before], ['quality_status' => $status],
                ['reason' => $reason, 'reservations_released' => $released]);

            return $locked;
        });
    }

    private function releaseReservations(Batch $batch): int
    {
        $reservations = StockReservation::query()->where('batch_id', $batch->id)->where('status', 'active')->lockForUpdate()->get();
        if ($reservations->isEmpty()) {
            return 0;
        }

        $deltas = [];
        foreach ($reservations as $r) {
            $key = "{$r->warehouse_id}|{$r->batch_id}";
            $deltas[$key] = bcsub($deltas[$key] ?? '0', (string) $r->quantity, 4);
        }
        $this->ledger->adjustReserved($deltas);
        StockReservation::query()->whereIn('id', $reservations->pluck('id'))->update(['status' => 'released', 'updated_at' => now()]);

        return $reservations->count();
    }
}

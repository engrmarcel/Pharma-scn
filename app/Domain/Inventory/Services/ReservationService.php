<?php

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Inventory\Support\StockLine;
use App\Support\Exceptions\BusinessRuleViolation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Holds sellable stock for an order (FEFO), then either consumes it (stock
 * leaves through the ledger) or releases it. Sales orders and POS build on this.
 */
class ReservationService
{
    public function __construct(
        private readonly FefoAllocator $fefo,
        private readonly InventoryLedger $ledger,
    ) {}

    /** @return Collection<int, StockReservation> */
    public function reserve(string $productId, string $warehouseId, string $quantity, string $referenceType, string $referenceId, ?\DateTimeInterface $expiresAt = null): Collection
    {
        return DB::transaction(function () use ($productId, $warehouseId, $quantity, $referenceType, $referenceId, $expiresAt) {
            $plan = $this->fefo->allocate($productId, $warehouseId, $quantity, lock: true);

            $this->ledger->adjustReserved(collect($plan)->mapWithKeys(fn ($qty, $batchId) => ["{$warehouseId}|{$batchId}" => $qty])->all());

            return collect($plan)->map(fn ($qty, $batchId) => StockReservation::query()->create([
                'warehouse_id' => $warehouseId,
                'product_id' => $productId,
                'batch_id' => $batchId,
                'quantity' => $qty,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'status' => 'active',
                'expires_at' => $expiresAt,
                'created_by' => Auth::id(),
            ]))->values();
        }, 3);
    }

    public function release(string $referenceType, string $referenceId): int
    {
        return DB::transaction(function () use ($referenceType, $referenceId) {
            $reservations = $this->active($referenceType, $referenceId);
            if ($reservations->isNotEmpty()) {
                $this->ledger->adjustReserved($reservations->groupBy(fn ($r) => "{$r->warehouse_id}|{$r->batch_id}")
                    ->map(fn ($g) => bcmul((string) $g->sum('quantity'), '-1', 4))->all());
                StockReservation::query()->whereIn('id', $reservations->pluck('id'))->update(['status' => 'released', 'updated_at' => now()]);
            }

            return $reservations->count();
        }, 3);
    }

    /**
     * Ship reserved stock: one posting removes it from on-hand and reserved together.
     */
    public function consume(string $referenceType, string $referenceId, string $movementType = 'sale'): string
    {
        return DB::transaction(function () use ($referenceType, $referenceId, $movementType) {
            $reservations = $this->active($referenceType, $referenceId);
            if ($reservations->isEmpty()) {
                throw new BusinessRuleViolation('reservation_missing', 'There is no active reservation to consume.', 409);
            }

            $lines = $reservations->map(fn (StockReservation $r) => new StockLine(
                $r->warehouse_id, $r->batch_id, 'available', bcmul((string) $r->quantity, '-1', 4), $movementType,
                reservedDelta: bcmul((string) $r->quantity, '-1', 4),
            ))->all();

            $postingId = $this->ledger->post($lines, $movementType, $referenceType, $referenceId);
            StockReservation::query()->whereIn('id', $reservations->pluck('id'))->update(['status' => 'consumed', 'updated_at' => now()]);

            return $postingId;
        }, 3);
    }

    private function active(string $referenceType, string $referenceId): Collection
    {
        return StockReservation::query()->where('reference_type', $referenceType)->where('reference_id', $referenceId)
            ->where('status', 'active')->lockForUpdate()->get();
    }
}

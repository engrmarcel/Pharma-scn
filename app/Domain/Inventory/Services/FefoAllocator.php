<?php

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Models\Batch;
use App\Support\Exceptions\BusinessRuleViolation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * First-Expiry-First-Out allocation (spec §10.3).
 *
 * Only *sellable* stock is eligible: available state, unreserved quantity,
 * batch released, not recalled and not expired. Organizations can also
 * require a minimum remaining shelf life (inventory.min_shelf_life_days).
 * Batches without an expiry date sort last.
 */
class FefoAllocator
{
    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * @param  bool  $lock  lock the chosen balance rows (call inside the transaction that will post)
     * @return array<string, string> batch_id => base-unit quantity, in allocation order
     */
    public function allocate(string $productId, string $warehouseId, string $quantity, bool $lock = false): array
    {
        if (bccomp($quantity, '0', 4) <= 0) {
            throw new BusinessRuleViolation('quantity_invalid', 'Quantity must be positive.');
        }

        $query = DB::table('inventory_balances as b')
            ->join('batches as bt', 'bt.id', '=', 'b.batch_id')
            ->where('b.organization_id', $this->tenant->id())
            ->where('b.product_id', $productId)
            ->where('b.warehouse_id', $warehouseId)
            ->where('b.stock_state', 'available')
            ->whereRaw('b.quantity - b.reserved_quantity > 0')
            ->where('bt.quality_status', 'released')
            ->where('bt.recall_status', 'none')
            ->where(fn ($q) => $q->whereNull('bt.expiry_date')->orWhere('bt.expiry_date', '>=', $this->earliestAcceptableExpiry()))
            ->orderByRaw('bt.expiry_date IS NULL')
            ->orderBy('bt.expiry_date')
            ->orderBy('bt.created_at')
            ->orderBy('bt.id')
            ->select(['b.batch_id', 'b.quantity', 'b.reserved_quantity']);

        if ($lock) {
            $query->lockForUpdate();
        }

        $plan = [];
        $remaining = $quantity;
        $available = '0';

        foreach ($query->get() as $row) {
            $free = bcsub((string) $row->quantity, (string) $row->reserved_quantity, 4);
            $available = bcadd($available, $free, 4);
            if (bccomp($remaining, '0', 4) <= 0) {
                continue;
            }
            $take = bccomp($free, $remaining, 4) < 0 ? $free : $remaining;
            $plan[$row->batch_id] = $take;
            $remaining = bcsub($remaining, $take, 4);
        }

        if (bccomp($remaining, '0', 4) > 0) {
            throw new BusinessRuleViolation('insufficient_stock', 'Not enough sellable stock (expired, recalled, quarantined and reserved stock is excluded).', 409, [
                'product_id' => $productId, 'warehouse_id' => $warehouseId, 'requested' => $quantity, 'sellable' => $available,
            ]);
        }

        return $plan;
    }

    /**
     * Validate an explicitly chosen batch (e.g. scanned at POS) against the same rules.
     */
    public function assertSellable(Batch $batch): void
    {
        $reason = $batch->saleBlockReason();
        if ($reason === null && $batch->expiry_date !== null && $batch->expiry_date->lt($this->earliestAcceptableExpiry())) {
            $reason = 'below_minimum_shelf_life';
        }

        if ($reason !== null) {
            throw new BusinessRuleViolation('batch_not_sellable', "Batch {$batch->batch_number} cannot be issued: {$reason}.", 409, ['batch_id' => $batch->id, 'reason' => $reason]);
        }
    }

    private function earliestAcceptableExpiry(): string
    {
        $days = (int) data_get($this->tenant->organization()?->settings, 'inventory.min_shelf_life_days', 0);

        return today()->addDays($days)->toDateString();
    }
}

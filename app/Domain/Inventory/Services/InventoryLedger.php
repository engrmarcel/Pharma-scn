<?php

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Events\InventoryAdjusted;
use App\Domain\Inventory\Events\StockReceived;
use App\Domain\Inventory\Events\StockTransferred;
use App\Domain\Inventory\Models\Batch;
use App\Domain\Inventory\Models\InventoryBalance;
use App\Domain\Inventory\Models\InventoryMovement;
use App\Domain\Inventory\Support\StockLine;
use App\Domain\Organizations\Models\Warehouse;
use App\Domain\Platform\Services\AuditLogger;
use App\Domain\Traceability\Services\TraceRecorder;
use App\Support\Events\DomainEvents;
use App\Support\Exceptions\BusinessRuleViolation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The single writer of stock quantities (spec §10.2).
 *
 * Every posting runs in one transaction that:
 *   1. resolves batches and warehouses through the tenant scope (foreign ids fail),
 *   2. creates missing balance rows and locks all touched rows with
 *      SELECT … FOR UPDATE in a fixed key order (deadlock avoidance),
 *   3. applies deltas, refusing negative stock (unless the organization's
 *      policy allows negative *available* stock) and refusing to dip below
 *      reserved quantities,
 *   4. appends immutable movement rows carrying the resulting balance.
 *
 * Deadlocks between concurrent postings are retried by the transaction.
 */
class InventoryLedger
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<StockLine>  $lines
     * @return string posting id
     */
    public function post(array $lines, ?string $reasonCode = null, ?string $referenceType = null, ?string $referenceId = null): string
    {
        if ($lines === []) {
            throw new BusinessRuleViolation('posting_empty', 'A stock posting needs at least one line.');
        }

        return DB::transaction(function () use ($lines, $reasonCode, $referenceType, $referenceId) {
            $organization = $this->tenant->organization() ?? throw new \LogicException('Inventory postings require a tenant context.');
            $allowNegative = (bool) data_get($organization->settings, 'inventory.allow_negative_stock', false);

            $batches = Batch::query()->whereIn('id', array_unique(array_map(fn ($l) => $l->batchId, $lines)))->get()->keyBy('id');
            $warehouseIds = array_unique(array_map(fn ($l) => $l->warehouseId, $lines));
            $warehouses = Warehouse::query()->whereIn('id', $warehouseIds)->pluck('id');

            foreach ($lines as $line) {
                if (! $batches->has($line->batchId) || ! $warehouses->contains($line->warehouseId)) {
                    throw new BusinessRuleViolation('stock_reference_invalid', 'Unknown batch or warehouse for this organization.', 404);
                }
            }

            $balances = $this->lockBalances($lines, $batches, $organization->id);
            $postingId = (string) Str::ulid();
            $now = now();
            $movements = [];

            foreach ($lines as $line) {
                $balance = $balances[$line->key()];
                $newQty = bcadd((string) $balance->quantity, $line->quantity, 4);
                $newReserved = bcadd((string) $balance->reserved_quantity, $line->reservedDelta, 4);

                if (bccomp($newReserved, '0', 4) < 0) {
                    throw new BusinessRuleViolation('reservation_underflow', 'Cannot release more than is reserved.', 409);
                }
                // Negative stock is only ever tolerated on unreserved available stock, and only by policy.
                $negativeAllowed = $allowNegative && $line->state === 'available' && bccomp($newReserved, '0', 4) === 0;
                if (bccomp($line->quantity, '0', 4) < 0 && ! $negativeAllowed) {
                    if (bccomp($newQty, '0', 4) < 0) {
                        throw new BusinessRuleViolation('insufficient_stock', 'Not enough stock for this movement.', 409, [
                            'batch_id' => $line->batchId, 'warehouse_id' => $line->warehouseId, 'state' => $line->state,
                            'on_hand' => (string) $balance->quantity, 'requested' => bcmul($line->quantity, '-1', 4),
                        ]);
                    }
                    if (bccomp($newQty, $newReserved, 4) < 0) {
                        throw new BusinessRuleViolation('stock_reserved', 'The remaining stock is reserved for other orders.', 409, [
                            'batch_id' => $line->batchId, 'on_hand' => (string) $balance->quantity, 'reserved' => (string) $balance->reserved_quantity,
                        ]);
                    }
                }

                $balance->quantity = $newQty;
                $balance->reserved_quantity = $newReserved;
                $balance->save();

                $movements[] = [
                    'id' => strtolower((string) Str::ulid()),
                    'organization_id' => $organization->id,
                    'posting_id' => $postingId,
                    'product_id' => $batches[$line->batchId]->product_id,
                    'batch_id' => $line->batchId,
                    'warehouse_id' => $line->warehouseId,
                    'bin_location_id' => $line->binLocationId,
                    'stock_state' => $line->state,
                    'quantity' => $line->quantity,
                    'movement_type' => $line->movementType,
                    'reason_code' => $reasonCode,
                    'reference_type' => $referenceType,
                    'reference_id' => $referenceId,
                    'unit_cost' => $line->unitCost,
                    'balance_after' => $newQty,
                    'posted_by' => Auth::id(),
                    'posted_at' => $now,
                    'correlation_id' => Context::get('correlation_id'),
                ];
            }

            InventoryMovement::query()->insert($movements);
            // Chain-of-custody events for this posting, in the same transaction (spec §16).
            app(TraceRecorder::class)->recordPosting($organization, $postingId, $movements, $reasonCode, $referenceType, $referenceId);

            $types = array_values(array_unique(array_column($movements, 'movement_type')));
            $this->audit->record('inventory.posted', null, metadata: [
                'posting_id' => $postingId, 'movement_types' => $types, 'reason_code' => $reasonCode,
                'reference_type' => $referenceType, 'reference_id' => $referenceId, 'lines' => count($movements),
            ]);
            $this->dispatchEvents($types, $postingId, $organization->id, $referenceType, $referenceId);

            return $postingId;
        }, 3);
    }

    /**
     * Change reserved quantities on available balances without moving stock.
     *
     * @param  array<string, string>  $deltas  "warehouseId|batchId" => signed quantity
     */
    public function adjustReserved(array $deltas): void
    {
        DB::transaction(function () use ($deltas) {
            ksort($deltas);
            foreach ($deltas as $key => $delta) {
                [$warehouseId, $batchId] = explode('|', $key);
                $balance = InventoryBalance::query()
                    ->where('warehouse_id', $warehouseId)->where('batch_id', $batchId)->where('stock_state', 'available')
                    ->lockForUpdate()->first();

                $reserved = bcadd((string) ($balance?->reserved_quantity ?? '0'), $delta, 4);
                if ($balance === null || bccomp($reserved, '0', 4) < 0 || bccomp($reserved, (string) $balance->quantity, 4) > 0) {
                    throw new BusinessRuleViolation('insufficient_stock', 'Not enough unreserved stock to reserve.', 409, ['batch_id' => $batchId, 'warehouse_id' => $warehouseId]);
                }

                $balance->reserved_quantity = $reserved;
                $balance->save();
            }
        }, 3);
    }

    /**
     * @return array<string, InventoryBalance>
     */
    private function lockBalances(array $lines, $batches, string $organizationId): array
    {
        $keys = [];
        foreach ($lines as $line) {
            $keys[$line->key()] = $line;
        }
        ksort($keys);

        $now = now();
        DB::table('inventory_balances')->insertOrIgnore(array_values(array_map(fn (StockLine $l) => [
            'organization_id' => $organizationId,
            'warehouse_id' => $l->warehouseId,
            'product_id' => $batches[$l->batchId]->product_id,
            'batch_id' => $l->batchId,
            'stock_state' => $l->state,
            'quantity' => 0,
            'reserved_quantity' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ], $keys)));

        $locked = [];
        foreach ($keys as $key => $line) {
            $locked[$key] = InventoryBalance::query()
                ->where('warehouse_id', $line->warehouseId)
                ->where('batch_id', $line->batchId)
                ->where('stock_state', $line->state)
                ->lockForUpdate()
                ->firstOrFail();
        }

        return $locked;
    }

    private function dispatchEvents(array $types, string $postingId, string $organizationId, ?string $referenceType, ?string $referenceId): void
    {
        if (in_array('receipt', $types, true)) {
            DomainEvents::raise(new StockReceived($postingId, $organizationId, $referenceType, $referenceId));
        }
        if (array_intersect($types, ['transfer_out', 'transfer_in']) !== []) {
            DomainEvents::raise(new StockTransferred($postingId, $organizationId, $referenceType, $referenceId));
        }
        if (array_intersect($types, ['adjustment', 'count', 'write_off', 'state_change']) !== []) {
            DomainEvents::raise(new InventoryAdjusted($postingId, $organizationId, $referenceType, $referenceId));
        }
    }
}

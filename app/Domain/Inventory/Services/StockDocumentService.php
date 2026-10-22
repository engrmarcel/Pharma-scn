<?php

namespace App\Domain\Inventory\Services;

use App\Domain\Finance\Services\AccountingBridge;
use App\Domain\Inventory\Models\Batch;
use App\Domain\Inventory\Models\InventoryBalance;
use App\Domain\Inventory\Models\StockAdjustment;
use App\Domain\Inventory\Models\StockCount;
use App\Domain\Inventory\Models\StockTransfer;
use App\Domain\Inventory\Support\StockLine;
use App\Domain\Organizations\Models\Warehouse;
use App\Domain\Platform\Services\AuditLogger;
use App\Support\Exceptions\BusinessRuleViolation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Stock adjustments, inter-warehouse transfers and stock counts. Every
 * quantity change goes through InventoryLedger in the same transaction as
 * the document update, so a document can never claim a movement that did
 * not happen.
 */
class StockDocumentService
{
    public const ADJUSTMENT_REASONS = ['damage', 'expiry', 'theft', 'found', 'count_correction', 'write_off', 'quality', 'recall', 'opening_balance', 'other'];

    public function __construct(
        private readonly InventoryLedger $ledger,
        private readonly FefoAllocator $fefo,
        private readonly DocumentNumberer $numbers,
        private readonly AuditLogger $audit,
        private readonly AccountingBridge $accounting,
    ) {}

    /**
     * @param  list<array{batch_id: string, stock_state?: string, quantity_delta: string}>  $lines
     */
    public function adjust(Warehouse $warehouse, string $reasonCode, array $lines, ?string $notes = null): StockAdjustment
    {
        return DB::transaction(function () use ($warehouse, $reasonCode, $lines, $notes) {
            $batches = Batch::query()->whereIn('id', array_column($lines, 'batch_id'))->get()->keyBy('id');

            $stockLines = [];
            foreach ($lines as $i => $line) {
                $batch = $batches[$line['batch_id']] ?? throw new BusinessRuleViolation('stock_reference_invalid', 'Unknown batch.', 404, ['line' => $i]);
                $state = $line['stock_state'] ?? 'available';

                // Found or corrected stock of a blocked batch must not become sellable.
                if ($state === 'available' && bccomp((string) $line['quantity_delta'], '0', 4) > 0 && $batch->saleBlockReason() !== null) {
                    throw new BusinessRuleViolation('batch_not_sellable', "Batch {$batch->batch_number} is {$batch->saleBlockReason()}; adjust it into the quarantined or damaged state instead.", 409, ['line' => $i]);
                }

                $type = in_array($reasonCode, ['write_off', 'expiry', 'damage'], true) && bccomp((string) $line['quantity_delta'], '0', 4) < 0 ? 'write_off' : 'adjustment';
                $stockLines[] = new StockLine($warehouse->id, $batch->id, $state, (string) $line['quantity_delta'], $type, $batch->unit_cost);
            }

            $adjustment = StockAdjustment::query()->create([
                'number' => $this->numbers->next('ADJ'),
                'warehouse_id' => $warehouse->id,
                'reason_code' => $reasonCode,
                'notes' => $notes,
                'created_by' => Auth::id(),
            ]);
            $adjustment->lines()->createMany(array_map(fn ($l) => [
                'batch_id' => $l['batch_id'], 'stock_state' => $l['stock_state'] ?? 'available', 'quantity_delta' => $l['quantity_delta'],
            ], $lines));

            $postingId = $this->ledger->post($stockLines, $reasonCode, 'stock_adjustment', $adjustment->id);
            $adjustment->forceFill(['posting_id' => $postingId, 'posted_at' => now()])->save();
            $this->accounting->stockAdjustment('stock_adjustment', $adjustment->id, "Stock adjustment {$adjustment->number} ({$reasonCode})",
                array_map(fn ($l) => ['batch_id' => $l['batch_id'], 'quantity' => (string) $l['quantity_delta']], $lines));

            return $adjustment->load('lines');
        });
    }

    /**
     * @param  list<array{product_id: string, quantity: string, batch_id?: ?string}>  $lines
     */
    public function createTransfer(Warehouse $from, Warehouse $to, array $lines, ?string $notes = null): StockTransfer
    {
        if ($from->id === $to->id) {
            throw new BusinessRuleViolation('transfer_same_warehouse', 'Source and destination must differ.');
        }

        return DB::transaction(function () use ($from, $to, $lines, $notes) {
            $transfer = StockTransfer::query()->create([
                'number' => $this->numbers->next('TRF'),
                'from_warehouse_id' => $from->id,
                'to_warehouse_id' => $to->id,
                'status' => 'draft',
                'notes' => $notes,
                'created_by' => Auth::id(),
            ]);
            $transfer->lines()->createMany(array_map(fn ($l) => [
                'product_id' => $l['product_id'], 'quantity' => $l['quantity'], 'batch_id' => $l['batch_id'] ?? null,
            ], $lines));
            $this->audit->record('stock_transfer.created', $transfer, new: ['number' => $transfer->number, 'lines' => count($lines)]);

            return $transfer->load('lines');
        });
    }

    /** Stock leaves the source (FEFO unless batches were chosen) and is in transit to the destination. */
    public function dispatchTransfer(StockTransfer $transfer): StockTransfer
    {
        return DB::transaction(function () use ($transfer) {
            $locked = StockTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            if ($locked->status !== 'draft') {
                throw new BusinessRuleViolation('transfer_state_invalid', "Transfer is {$locked->status}.", 409);
            }

            $stockLines = [];
            foreach ($locked->lines()->get() as $line) {
                if ($line->batch_id !== null) {
                    $this->fefo->assertSellable(Batch::query()->findOrFail($line->batch_id));
                    $plan = [$line->batch_id => (string) $line->quantity];
                } else {
                    $plan = $this->fefo->allocate($line->product_id, $locked->from_warehouse_id, (string) $line->quantity, lock: true);
                }

                // Split the line per allocated batch so receipt is tracked per batch.
                $first = true;
                foreach ($plan as $batchId => $qty) {
                    if ($first) {
                        $line->forceFill(['batch_id' => $batchId, 'quantity' => $qty])->save();
                        $first = false;
                    } else {
                        $locked->lines()->create(['product_id' => $line->product_id, 'batch_id' => $batchId, 'quantity' => $qty]);
                    }
                    $stockLines[] = new StockLine($locked->from_warehouse_id, $batchId, 'available', bcmul($qty, '-1', 4), 'transfer_out');
                    $stockLines[] = new StockLine($locked->to_warehouse_id, $batchId, 'in_transit', $qty, 'transfer_out');
                }
            }

            $this->ledger->post($stockLines, 'transfer_dispatch', 'stock_transfer', $locked->id);
            $locked->forceFill(['status' => 'dispatched', 'dispatched_by' => Auth::id(), 'dispatched_at' => now()])->save();

            return $locked->load('lines');
        });
    }

    /**
     * Receive some or all of a dispatched transfer. Unreceived quantity stays
     * in transit (visible for investigation); it is never silently dropped.
     *
     * @param  array<int, string>  $received  transfer line id => quantity received now
     */
    public function receiveTransfer(StockTransfer $transfer, array $received): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $received) {
            $locked = StockTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            if (! in_array($locked->status, ['dispatched', 'partially_received'], true)) {
                throw new BusinessRuleViolation('transfer_state_invalid', "Transfer is {$locked->status}.", 409);
            }

            $lines = $locked->lines()->get()->keyBy('id');
            $stockLines = [];
            foreach ($received as $lineId => $qty) {
                $line = $lines[$lineId] ?? throw new BusinessRuleViolation('transfer_line_unknown', "Unknown transfer line {$lineId}.", 422);
                $outstanding = bcsub((string) $line->quantity, (string) $line->received_quantity, 4);
                if (bccomp((string) $qty, '0', 4) <= 0 || bccomp((string) $qty, $outstanding, 4) > 0) {
                    throw new BusinessRuleViolation('transfer_quantity_invalid', "Line {$lineId}: receive between 0 and {$outstanding}.", 422);
                }

                $batch = Batch::query()->findOrFail($line->batch_id);
                // A batch blocked while in transit arrives quarantined, not sellable.
                $state = $batch->saleBlockReason() === null ? 'available' : 'quarantined';
                $stockLines[] = new StockLine($locked->to_warehouse_id, $line->batch_id, 'in_transit', bcmul((string) $qty, '-1', 4), 'transfer_in');
                $stockLines[] = new StockLine($locked->to_warehouse_id, $line->batch_id, $state, (string) $qty, 'transfer_in');
                $line->forceFill(['received_quantity' => bcadd((string) $line->received_quantity, (string) $qty, 4)])->save();
            }

            $this->ledger->post($stockLines, 'transfer_receipt', 'stock_transfer', $locked->id);

            $complete = $locked->lines()->get()->every(fn ($l) => bccomp((string) $l->quantity, (string) $l->received_quantity, 4) === 0);
            $locked->forceFill(['status' => $complete ? 'received' : 'partially_received', 'received_by' => Auth::id(), 'received_at' => now()])->save();

            return $locked->load('lines');
        });
    }

    public function cancelTransfer(StockTransfer $transfer): StockTransfer
    {
        if ($transfer->status !== 'draft') {
            throw new BusinessRuleViolation('transfer_state_invalid', 'Only draft transfers can be cancelled; dispatched stock must be received.', 409);
        }
        $transfer->forceFill(['status' => 'cancelled'])->save();
        $this->audit->record('stock_transfer.cancelled', $transfer);

        return $transfer;
    }

    public function openCount(Warehouse $warehouse, ?string $notes = null): StockCount
    {
        return DB::transaction(fn () => StockCount::query()->create([
            'number' => $this->numbers->next('CNT'),
            'warehouse_id' => $warehouse->id,
            'status' => 'open',
            'notes' => $notes,
            'created_by' => Auth::id(),
        ]));
    }

    /** @param list<array{batch_id: string, counted_quantity: string}> $lines */
    public function recordCountLines(StockCount $count, array $lines): StockCount
    {
        if ($count->status !== 'open') {
            throw new BusinessRuleViolation('count_state_invalid', 'Only open counts can be edited.', 409);
        }
        $known = Batch::query()->whereIn('id', array_column($lines, 'batch_id'))->pluck('id');

        foreach ($lines as $line) {
            if (! $known->contains($line['batch_id'])) {
                throw new BusinessRuleViolation('stock_reference_invalid', 'Unknown batch.', 404);
            }
            $count->lines()->updateOrCreate(['batch_id' => $line['batch_id']], ['counted_quantity' => $line['counted_quantity']]);
        }

        return $count->load('lines');
    }

    /** Post differences between counted and current available stock, as of the moment of posting. */
    public function postCount(StockCount $count): StockCount
    {
        return DB::transaction(function () use ($count) {
            $locked = StockCount::query()->lockForUpdate()->findOrFail($count->id);
            if ($locked->status !== 'open') {
                throw new BusinessRuleViolation('count_state_invalid', 'Count is already posted or cancelled.', 409);
            }

            $stockLines = [];
            foreach ($locked->lines()->get() as $line) {
                $balance = InventoryBalance::query()->where('warehouse_id', $locked->warehouse_id)->where('batch_id', $line->batch_id)
                    ->where('stock_state', 'available')->lockForUpdate()->first();
                $system = (string) ($balance?->quantity ?? '0');
                $line->forceFill(['system_quantity' => $system])->save();

                $delta = bcsub((string) $line->counted_quantity, $system, 4);
                if (bccomp($delta, '0', 4) !== 0) {
                    $stockLines[] = new StockLine($locked->warehouse_id, $line->batch_id, 'available', $delta, 'count');
                }
            }

            $postingId = $stockLines === [] ? null : $this->ledger->post($stockLines, 'count_correction', 'stock_count', $locked->id);
            if ($stockLines !== []) {
                $this->accounting->stockAdjustment('stock_count', $locked->id, "Stock count {$locked->number}",
                    array_map(fn (StockLine $l) => ['batch_id' => $l->batchId, 'quantity' => $l->quantity], $stockLines));
            }
            $locked->forceFill(['status' => 'posted', 'posting_id' => $postingId, 'posted_by' => Auth::id(), 'posted_at' => now()])->save();
            $this->audit->record('stock_count.posted', $locked, new: ['lines' => $locked->lines()->count(), 'adjusted_lines' => count($stockLines)]);

            return $locked->load('lines');
        });
    }
}

<?php

namespace App\Domain\Sync\Services;

use App\Domain\Finance\Services\AccountingBridge;
use App\Domain\Inventory\Models\Batch;
use App\Domain\Inventory\Services\DirectReceiptService;
use App\Domain\Inventory\Services\InventoryLedger;
use App\Domain\Inventory\Support\StockLine;
use App\Domain\Organizations\Models\Warehouse;
use App\Domain\Platform\Services\AuditLogger;
use App\Domain\Pos\Models\PosSale;
use App\Domain\Pos\Models\PosSaleAllocation;
use App\Domain\Pos\Models\PosSaleLine;
use App\Domain\Sync\Models\SyncIssue;
use App\Models\User;
use App\Support\Exceptions\BusinessRuleViolation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Raising and resolving reconciliation issues found while replaying offline
 * transactions. Issues are never deleted; resolving one records who did
 * what and why.
 *
 * A stock shortfall means goods were sold that the system did not know
 * about (for example two tills selling the last units while offline). It
 * is resolved either by acknowledging it, or by recording the stock that
 * was physically there (a found-stock receipt into a batch) and issuing it
 * to the sale, which posts the missing cost of goods sold. Balances are
 * never overwritten.
 */
class SyncIssueService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly DirectReceiptService $receipts,
        private readonly InventoryLedger $ledger,
        private readonly AccountingBridge $accounting,
    ) {}

    public function raise(string $type, string $severity, array $attributes): SyncIssue
    {
        $issue = SyncIssue::query()->create(['type' => $type, 'severity' => $severity] + $attributes);
        $this->audit->record('sync_issue.raised', $issue, new: ['type' => $type, 'severity' => $severity, 'subject' => $attributes['subject_type'] ?? null]);

        return $issue;
    }

    public function resolve(SyncIssue $issue, User $actor, array $data): SyncIssue
    {
        return DB::transaction(function () use ($issue, $actor, $data) {
            $locked = SyncIssue::query()->lockForUpdate()->findOrFail($issue->id);
            if ($locked->status !== 'open') {
                throw new BusinessRuleViolation('sync_issue_closed', 'This issue is already resolved.', 409);
            }

            $resolution = match ($data['action']) {
                'acknowledge' => 'acknowledged',
                'record_found_stock' => $this->recordFoundStock($locked, $data),
            };

            $locked->forceFill(['status' => 'resolved', 'resolution' => $resolution, 'resolution_notes' => $data['notes'], 'resolved_by' => $actor->id, 'resolved_at' => now()])->save();
            $this->audit->record('sync_issue.resolved', $locked, new: ['resolution' => $resolution, 'notes' => $data['notes']]);

            return $locked;
        });
    }

    private function recordFoundStock(SyncIssue $issue, array $data): string
    {
        if ($issue->type !== 'stock_shortfall' || $issue->subject_type !== 'pos_sale_line') {
            throw new BusinessRuleViolation('resolution_not_applicable', 'Recording found stock applies only to POS stock shortfalls.', 422);
        }
        if (blank($data['batch_id'] ?? null) && (blank($data['batch_number'] ?? null) || blank($data['expiry_date'] ?? null))) {
            throw new BusinessRuleViolation('batch_required', 'Choose the batch the goods came from, or give its batch number and expiry date.', 422);
        }

        $line = PosSaleLine::query()->lockForUpdate()->findOrFail($issue->subject_id);
        $sale = PosSale::query()->findOrFail($line->pos_sale_id);
        $quantity = (string) $line->unallocated_base_quantity;
        if (bccomp($quantity, '0', 4) <= 0) {
            throw new BusinessRuleViolation('shortfall_already_issued', 'Nothing is left to issue for this line.', 409);
        }
        if (isset($data['batch_id'])) {
            $existing = Batch::query()->where('product_id', $line->product_id)->find($data['batch_id'])
                ?? throw new BusinessRuleViolation('batch_mismatch', 'The batch does not belong to this product.', 422);
            $data['batch_number'] = $existing->batch_number;
            $data['expiry_date'] = $existing->expiry_date?->toDateString();
        }
        $expired = filled($data['expiry_date'] ?? null) && Carbon::parse($data['expiry_date'])->lt(today());

        // 1. Record the stock that was physically there (Dr Inventory, Cr Inventory adjustment), in base units.
        $received = $this->receipts->receive(Warehouse::query()->findOrFail($sale->warehouse_id), [array_filter([
            'product_id' => $line->product_id, 'quantity' => $quantity, 'batch_number' => $data['batch_number'],
            'expiry_date' => $data['expiry_date'] ?? null, 'unit_cost' => $data['unit_cost'] ?? null,
            // Expired goods can still be recorded (they were sold), just never as sellable.
            'quality_status' => $expired ? 'quarantined' : null,
        ], fn ($v) => $v !== null)], reasonCode: 'found_stock');
        $batch = Batch::query()->findOrFail($received['batches'][0]);

        // 2. Issue it to the sale from whatever state the receipt put it in (Dr COGS, Cr Inventory).
        $state = $batch->quality_status === 'released' && $batch->recall_status === 'none' ? 'available' : 'quarantined';
        $this->ledger->post([new StockLine($sale->warehouse_id, $batch->id, $state, bcmul($quantity, '-1', 4), 'sale')], 'sale', 'pos_sale_line', $line->id);
        PosSaleAllocation::query()->create(['pos_sale_line_id' => $line->id, 'batch_id' => $batch->id, 'base_quantity' => $quantity, 'unit_cost' => $batch->unit_cost]);
        $this->accounting->deliveryCost('pos_sale', $sale->id, "POS sale {$sale->number}: offline shortfall issued", [['batch_id' => $batch->id, 'quantity' => $quantity]], today()->toDateString());
        $line->forceFill(['unallocated_base_quantity' => 0])->save();

        return 'found_stock_recorded';
    }
}

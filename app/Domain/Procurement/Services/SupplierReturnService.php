<?php

namespace App\Domain\Procurement\Services;

use App\Domain\Finance\Services\AccountingBridge;
use App\Domain\Inventory\Models\Batch;
use App\Domain\Inventory\Services\DocumentNumberer;
use App\Domain\Inventory\Services\InventoryLedger;
use App\Domain\Inventory\Support\StockLine;
use App\Domain\Organizations\Models\Warehouse;
use App\Domain\Parties\Models\Supplier;
use App\Domain\Platform\Services\AuditLogger;
use App\Domain\Procurement\Models\SupplierReturn;
use App\Support\Exceptions\BusinessRuleViolation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Stock sent back to a supplier. Returns can come from any stock state
 * (typically quarantined or damaged) and leave through the ledger.
 */
class SupplierReturnService
{
    public const REASONS = ['damaged', 'expired', 'wrong_item', 'quality', 'recall', 'overstock', 'other'];

    public function __construct(
        private readonly InventoryLedger $ledger,
        private readonly DocumentNumberer $numbers,
        private readonly AuditLogger $audit,
        private readonly AccountingBridge $accounting,
    ) {}

    /** @param list<array{batch_id: string, base_quantity: string, stock_state?: ?string}> $lines */
    public function post(Supplier $supplier, Warehouse $warehouse, string $reasonCode, array $lines, ?string $goodsReceiptId = null, ?string $notes = null): SupplierReturn
    {
        return DB::transaction(function () use ($supplier, $warehouse, $reasonCode, $lines, $goodsReceiptId, $notes) {
            $known = Batch::query()->whereIn('id', array_column($lines, 'batch_id'))->get()->keyBy('id');

            $return = SupplierReturn::query()->create([
                'number' => $this->numbers->next('SRT'),
                'supplier_id' => $supplier->id,
                'warehouse_id' => $warehouse->id,
                'goods_receipt_id' => $goodsReceiptId,
                'reason_code' => $reasonCode,
                'notes' => $notes,
                'created_by' => Auth::id(),
            ]);

            $stockLines = [];
            foreach ($lines as $i => $line) {
                $batch = $known[$line['batch_id']] ?? throw new BusinessRuleViolation('stock_reference_invalid', 'Unknown batch.', 404, ['line' => $i]);
                $state = $line['stock_state'] ?? 'available';
                $return->lines()->create(['batch_id' => $batch->id, 'stock_state' => $state, 'base_quantity' => $line['base_quantity']]);
                $stockLines[] = new StockLine($warehouse->id, $batch->id, $state, bcmul((string) $line['base_quantity'], '-1', 4), 'supplier_return', $batch->unit_cost);
            }

            $postingId = $this->ledger->post($stockLines, $reasonCode, 'supplier_return', $return->id);
            $return->forceFill(['posting_id' => $postingId, 'posted_at' => now()])->save();
            $this->accounting->supplierReturn($return);
            $this->audit->record('supplier_return.posted', $return, new: ['number' => $return->number, 'supplier_id' => $supplier->id, 'lines' => count($lines)]);

            return $return->load('lines');
        });
    }
}

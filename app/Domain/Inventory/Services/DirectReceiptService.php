<?php

namespace App\Domain\Inventory\Services;

use App\Domain\Finance\Services\AccountingBridge;
use App\Domain\Inventory\Models\InventoryMovement;
use App\Domain\Organizations\Models\Warehouse;
use Illuminate\Support\Facades\DB;

/**
 * Receipts that are not against a purchase order (opening balances, found
 * stock, customer returns): stock and the matching journal entry commit
 * together. Purchase-order receipts go through GoodsReceiptService instead.
 */
class DirectReceiptService
{
    public function __construct(
        private readonly StockReceiptService $receipts,
        private readonly AccountingBridge $accounting,
    ) {}

    public function receive(Warehouse $warehouse, array $lines, ?string $supplierId = null, string $reasonCode = 'receipt'): array
    {
        return DB::transaction(function () use ($warehouse, $lines, $supplierId, $reasonCode) {
            $result = $this->receipts->receive($warehouse, $lines, $supplierId, 'direct_receipt', null, $reasonCode);

            $deltas = InventoryMovement::query()->where('posting_id', $result['posting_id'])->get(['batch_id', 'quantity'])
                ->map(fn ($m) => ['batch_id' => $m->batch_id, 'quantity' => (string) $m->quantity])->all();
            $journal = $this->accounting->directReceipt($result['posting_id'], $reasonCode, $deltas);

            return $result + ['journal_entry_id' => $journal?->id];
        });
    }
}

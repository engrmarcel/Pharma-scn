<?php

namespace App\Domain\Procurement\Services;

use App\Domain\Finance\Services\AccountingBridge;
use App\Domain\Inventory\Models\Batch;
use App\Domain\Inventory\Services\DocumentNumberer;
use App\Domain\Inventory\Services\StockReceiptService;
use App\Domain\Organizations\Models\Warehouse;
use App\Domain\Platform\Services\AuditLogger;
use App\Domain\Procurement\Models\GoodsReceipt;
use App\Domain\Procurement\Models\PurchaseOrder;
use App\Support\Exceptions\BusinessRuleViolation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Goods received against a purchase order (GRN). Batch capture and stock
 * posting go through StockReceiptService, so a GRN obeys exactly the same
 * rules as any other receipt (catalogue, expiry, quarantine, ledger).
 */
class GoodsReceiptService
{
    public function __construct(
        private readonly StockReceiptService $receipts,
        private readonly DocumentNumberer $numbers,
        private readonly ProcurementPolicy $policy,
        private readonly AuditLogger $audit,
        private readonly AccountingBridge $accounting,
    ) {}

    /**
     * @param  list<array{purchase_order_line_id: int, batch_number: string, expiry_date?: ?string, manufacturing_date?: ?string,
     *                    quantity: string, quality_status?: ?string, bin_location_id?: ?string}>  $lines  quantity in the PO line's package level
     */
    public function receive(PurchaseOrder $order, array $lines, ?string $deliveryNote = null, ?string $notes = null): GoodsReceipt
    {
        return DB::transaction(function () use ($order, $lines, $deliveryNote, $notes) {
            $po = PurchaseOrder::query()->lockForUpdate()->findOrFail($order->id);
            if (! in_array($po->status, ['approved', 'sent', 'partially_received'], true)) {
                throw new BusinessRuleViolation('purchase_order_not_receivable', "Cannot receive against a {$po->status} purchase order.", 409);
            }

            $poLines = $po->lines()->lockForUpdate()->get()->keyBy('id');
            $grn = GoodsReceipt::query()->create([
                'number' => $this->numbers->next('GRN'),
                'purchase_order_id' => $po->id,
                'supplier_id' => $po->supplier_id,
                'warehouse_id' => $po->warehouse_id,
                'supplier_delivery_note' => $deliveryNote,
                'received_by' => Auth::id(),
                'received_at' => now(),
                'notes' => $notes,
            ]);

            $receiptLines = [];
            $bases = [];
            $incoming = [];
            foreach ($lines as $i => $line) {
                $poLine = $poLines[$line['purchase_order_line_id']] ?? throw new BusinessRuleViolation('purchase_order_line_unknown', 'Line does not belong to this purchase order.', 422, ['line' => $i]);
                $perUnit = bcdiv((string) $poLine->base_quantity, (string) $poLine->quantity, 8);
                $base = bcmul((string) $line['quantity'], $perUnit, 4);

                $bases[$i] = $base;
                $incoming[$poLine->id] = bcadd($incoming[$poLine->id] ?? '0', $base, 4);
                $after = bcadd((string) $poLine->received_base_quantity, $incoming[$poLine->id], 4);
                if (bccomp($after, $this->policy->maxReceivable((string) $poLine->base_quantity), 4) > 0) {
                    throw new BusinessRuleViolation('over_receipt', 'Received quantity exceeds the ordered quantity (plus tolerance).', 422, [
                        'line' => $i, 'ordered_base' => (string) $poLine->base_quantity, 'already_received_base' => (string) $poLine->received_base_quantity,
                    ]);
                }

                $receiptLines[] = [
                    'product_id' => $poLine->product_id,
                    'batch_number' => $line['batch_number'],
                    'expiry_date' => $line['expiry_date'] ?? null,
                    'manufacturing_date' => $line['manufacturing_date'] ?? null,
                    'quantity' => (string) $line['quantity'],
                    'package_level_code' => $poLine->package_level_code,
                    'unit_cost' => (string) $poLine->unit_price,
                    'quality_status' => $line['quality_status'] ?? 'released',
                    'bin_location_id' => $line['bin_location_id'] ?? null,
                ];
            }

            $result = $this->receipts->receive(Warehouse::query()->findOrFail($po->warehouse_id), $receiptLines, $po->supplier_id, 'goods_receipt', $grn->id, 'supplier_delivery');

            foreach ($lines as $i => $line) {
                $poLine = $poLines[$line['purchase_order_line_id']];
                $grn->lines()->create([
                    'purchase_order_line_id' => $poLine->id,
                    'product_id' => $poLine->product_id,
                    'batch_id' => Batch::query()->where('product_id', $poLine->product_id)->where('batch_number', strtoupper(trim($line['batch_number'])))->value('id'),
                    'quantity' => $line['quantity'],
                    'base_quantity' => $bases[$i],
                    'quality_status' => $receiptLines[$i]['quality_status'],
                ]);
            }
            foreach ($incoming as $lineId => $base) {
                $poLines[$lineId]->forceFill(['received_base_quantity' => bcadd((string) $poLines[$lineId]->received_base_quantity, $base, 4)])->save();
            }

            $complete = $po->lines()->get()->every(fn ($l) => bccomp((string) $l->received_base_quantity, (string) $l->base_quantity, 4) >= 0);
            $po->forceFill(['status' => $complete ? 'received' : 'partially_received'])->save();
            $journal = $this->accounting->goodsReceipt($grn->fresh());
            $grn->forceFill(['posting_id' => $result['posting_id'], 'journal_entry_id' => $journal?->id])->save();

            $this->audit->record('goods_receipt.posted', $grn, new: ['number' => $grn->number, 'purchase_order' => $po->number, 'lines' => count($lines), 'po_status' => $po->status]);

            return $grn->load('lines');
        });
    }
}

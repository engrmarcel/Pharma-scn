<?php

namespace App\Domain\Marketplace\Services;

use App\Domain\Catalogue\Services\UnitConverter;
use App\Domain\Inventory\Models\Batch;
use App\Domain\Inventory\Services\DocumentNumberer;
use App\Domain\Marketplace\Models\MarketplaceDispute;
use App\Domain\Marketplace\Models\MarketplaceInvoice;
use App\Domain\Marketplace\Models\MarketplaceOrder;
use App\Domain\Marketplace\Models\MarketplaceShipment;
use App\Domain\Marketplace\Models\TradingRelationship;
use App\Domain\Organizations\Models\Warehouse;
use App\Domain\Parties\Models\Supplier;
use App\Domain\Platform\Services\AuditLogger;
use App\Domain\Procurement\Services\SupplierReturnService;
use App\Domain\Sales\Models\SalesInvoice;
use App\Domain\Sales\Models\SalesOrder;
use App\Domain\Sales\Services\FulfilmentService;
use App\Support\Exceptions\BusinessRuleViolation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Returns and disputes (spec §14.2). Either side raises a dispute about an
 * accepted order; both sides exchange messages; the supplier resolves it
 * (credit note in its own books, replacement, or a reasoned rejection); the
 * buyer posts any return of goods in its own books; either side can escalate
 * to the platform, which can comment but never posts to either ERP.
 */
class DisputeService
{
    private const RETURN_REASONS = ['damaged' => 'damaged', 'quality' => 'quality', 'expiry' => 'expired', 'wrong_item' => 'wrong_item'];

    public function __construct(
        private readonly MarketplaceOrderService $orders,
        private readonly FulfilmentService $fulfilment,
        private readonly SupplierReturnService $returns,
        private readonly UnitConverter $units,
        private readonly DocumentNumberer $numbers,
        private readonly AuditLogger $audit,
    ) {}

    public function raise(MarketplaceOrder $order, array $data): MarketplaceDispute
    {
        $side = $this->orders->side($order);
        if (! in_array($order->status, ['accepted', 'partially_shipped', 'shipped', 'partially_received', 'received'], true)) {
            throw new BusinessRuleViolation('dispute_not_allowed', "Disputes can be raised once an order is accepted (it is {$order->status}).", 409);
        }
        $lines = $order->lines()->get()->keyBy('id');
        foreach ($data['lines'] ?? [] as $i => $l) {
            $line = $lines[$l['marketplace_order_line_id']] ?? throw new BusinessRuleViolation('order_line_unknown', 'That line is not on this order.', 422, ['line' => $i]);
            if (bccomp((string) $l['quantity'], (string) $line->quantity, 4) > 0) {
                throw new BusinessRuleViolation('dispute_quantity_invalid', 'You cannot dispute more than was ordered.', 422, ['line' => $i]);
            }
        }

        return DB::transaction(function () use ($order, $data, $side) {
            $dispute = MarketplaceDispute::query()->create([
                'number' => $this->numbers->next('MDS'), 'marketplace_order_id' => $order->id,
                'buyer_organization_id' => $order->buyer_organization_id, 'supplier_organization_id' => $order->supplier_organization_id,
                'raised_by_side' => $side, 'type' => $data['type'], 'requested_resolution' => $data['requested_resolution'],
                'description' => $data['description'], 'lines' => $data['lines'] ?? null,
            ]);
            $dispute->messages()->create(['author_side' => $side, 'user_id' => Auth::id(), 'body' => $data['description']]);
            $this->audit->record('marketplace_dispute.raised', null, new: ['dispute' => $dispute->number, 'order' => $order->number, 'type' => $data['type']]);

            return $dispute->load('messages');
        });
    }

    public function message(MarketplaceDispute $dispute, string $side, string $body): MarketplaceDispute
    {
        $dispute->messages()->create(['author_side' => $side, 'user_id' => Auth::id(), 'body' => $body]);

        return $dispute->load('messages');
    }

    /**
     * Supplier: resolve. A credit note is posted in the supplier's books against the
     * order's invoice; returned goods (with their batch) go back into the supplier's
     * stock as quarantined.
     *
     * @param  list<array{marketplace_order_line_id: int, quantity: string, batch_number?: ?string}>  $creditLines
     */
    public function resolve(MarketplaceDispute $dispute, string $resolution, string $notes, array $creditLines = [], bool $goodsReturned = false): MarketplaceDispute
    {
        return DB::transaction(function () use ($dispute, $resolution, $notes, $creditLines, $goodsReturned) {
            $locked = $this->lockAs($dispute, 'supplier', ['open', 'escalated']);
            $order = MarketplaceOrder::query()->findOrFail($locked->marketplace_order_id);
            $creditNoteId = null;

            if ($resolution === 'credit_note') {
                $invoice = MarketplaceInvoice::query()->where('marketplace_order_id', $order->id)->latest()->first()
                    ?? throw new BusinessRuleViolation('order_not_invoiced', 'Invoice the order before issuing a credit note.', 409);
                $sales = SalesInvoice::query()->with('lines')->findOrFail($invoice->supplier_sales_invoice_id);
                $orderLines = $order->lines()->get()->keyBy('id');
                $so = SalesOrder::query()->findOrFail($order->supplier_sales_order_id);

                $lines = [];
                foreach ($creditLines as $i => $l) {
                    $ol = $orderLines[$l['marketplace_order_line_id']] ?? throw new BusinessRuleViolation('order_line_unknown', 'That line is not on this order.', 422, ['line' => $i]);
                    $invLine = $sales->lines->firstWhere('sales_order_line_id', $ol->supplier_sales_order_line_id)
                        ?? throw new BusinessRuleViolation('line_not_invoiced', 'That line has not been invoiced.', 422, ['line' => $i]);
                    $line = ['sales_invoice_line_id' => $invLine->id, 'quantity' => (string) $l['quantity']];
                    if ($goodsReturned && filled($l['batch_number'] ?? null)) {
                        $line['batch_id'] = Batch::query()->where('product_id', $ol->product_id)->where('batch_number', strtoupper($l['batch_number']))->value('id')
                            ?? throw new BusinessRuleViolation('batch_unknown', "Batch {$l['batch_number']} is not one of yours.", 422, ['line' => $i]);
                        $line['restock_state'] = 'quarantined';
                    }
                    $lines[] = $line;
                }
                if ($lines === []) {
                    throw new BusinessRuleViolation('credit_lines_required', 'Say which lines and quantities are credited.', 422);
                }
                $note = $this->fulfilment->creditNote($sales, $goodsReturned ? 'return' : 'other', $lines, $goodsReturned ? $so->warehouse_id : null,
                    "Marketplace dispute {$locked->number}: {$notes}");
                $creditNoteId = $note->id;
            }

            $locked->forceFill(['status' => 'resolved', 'resolution' => $resolution, 'resolution_notes' => $notes, 'resolved_by' => Auth::id(),
                'resolved_at' => now(), 'supplier_credit_note_id' => $creditNoteId])->save();
            $locked->messages()->create(['author_side' => 'supplier', 'user_id' => Auth::id(), 'body' => "Resolved ({$resolution}): {$notes}"]);
            $this->audit->record('marketplace_dispute.resolved', null, new: ['dispute' => $locked->number, 'resolution' => $resolution, 'credit_note_id' => $creditNoteId]);

            return $locked->load('messages');
        });
    }

    /**
     * Buyer: send goods back. Posted as a supplier return in the buyer's books, from the batch that was received.
     *
     * @param  list<array{marketplace_order_line_id: int, quantity: string, batch_number: string}>  $lines
     */
    public function returnGoods(MarketplaceDispute $dispute, array $lines): MarketplaceDispute
    {
        return DB::transaction(function () use ($dispute, $lines) {
            $locked = $this->lockAs($dispute, 'buyer', ['open', 'escalated', 'resolved']);
            if ($locked->buyer_supplier_return_id !== null) {
                throw new BusinessRuleViolation('return_already_posted', 'The return for this dispute is already posted.', 409);
            }
            $order = MarketplaceOrder::query()->findOrFail($locked->marketplace_order_id);
            $orderLines = $order->lines()->get()->keyBy('id');
            $returnLines = [];
            foreach ($lines as $i => $l) {
                $ol = $orderLines[$l['marketplace_order_line_id']] ?? throw new BusinessRuleViolation('order_line_unknown', 'That line is not on this order.', 422, ['line' => $i]);
                $batch = Batch::query()->where('product_id', $ol->product_id)->where('batch_number', strtoupper($l['batch_number']))->first()
                    ?? throw new BusinessRuleViolation('batch_unknown', "Batch {$l['batch_number']} was not received.", 422, ['line' => $i]);
                $returnLines[] = ['batch_id' => $batch->id, 'base_quantity' => $this->units->toBase($ol->product_id, $ol->package_level_code, (string) $l['quantity']), 'stock_state' => 'available'];
            }
            $receiptId = MarketplaceShipment::query()->where('marketplace_order_id', $order->id)->whereNotNull('buyer_goods_receipt_id')->latest('received_at')->value('buyer_goods_receipt_id');
            $supplier = Supplier::query()->findOrFail(TradingRelationship::query()->findOrFail($order->trading_relationship_id)->buyer_supplier_id);
            $return = $this->returns->post($supplier, Warehouse::query()->findOrFail($order->delivery_warehouse_id), self::RETURN_REASONS[$locked->type] ?? 'other',
                $returnLines, $receiptId, "Marketplace dispute {$locked->number}");

            $locked->forceFill(['buyer_supplier_return_id' => $return->id])->save();
            $locked->messages()->create(['author_side' => 'buyer', 'user_id' => Auth::id(), 'body' => "Goods returned: return {$return->number}."]);

            return $locked->load('messages');
        });
    }

    public function escalate(MarketplaceDispute $dispute, string $side, string $reason): MarketplaceDispute
    {
        return DB::transaction(function () use ($dispute, $side, $reason) {
            $locked = $this->lockAs($dispute, $side, ['open', 'resolved']);
            $locked->forceFill(['status' => 'escalated', 'escalated_at' => now()])->save();
            $locked->messages()->create(['author_side' => $side, 'user_id' => Auth::id(), 'body' => "Escalated to the platform: {$reason}"]);
            $this->audit->record('marketplace_dispute.escalated', null, new: ['dispute' => $locked->number, 'by' => $side]);

            return $locked->load('messages');
        });
    }

    private function lockAs(MarketplaceDispute $dispute, string $side, array $statuses): MarketplaceDispute
    {
        $locked = MarketplaceDispute::query()->lockForUpdate()->findOrFail($dispute->id);
        $actual = $this->orders->side(MarketplaceOrder::query()->findOrFail($locked->marketplace_order_id));
        if ($actual !== $side) {
            throw new BusinessRuleViolation('dispute_wrong_side', "Only the {$side} can do this.", 403);
        }
        if (! in_array($locked->status, $statuses, true)) {
            throw new BusinessRuleViolation('dispute_state_invalid', "The dispute is {$locked->status}.", 409);
        }

        return $locked;
    }
}

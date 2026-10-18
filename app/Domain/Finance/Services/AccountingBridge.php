<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\JournalEntry;
use App\Domain\Inventory\Models\Batch;
use App\Domain\Procurement\Models\GoodsReceipt;
use App\Domain\Procurement\Models\PurchaseOrderLine;
use App\Domain\Procurement\Models\SupplierInvoice;
use App\Domain\Procurement\Models\SupplierReturn;
use App\Domain\Sales\Models\CreditNote;
use App\Domain\Sales\Models\SalesInvoice;
use App\Support\Money;

/**
 * Translates operational documents into journal entries. Called inside the
 * same transaction as the document, so stock and books cannot diverge.
 * Inventory is valued at batch cost (specific identification); stock with no
 * recorded cost posts nothing and is reported as uncosted.
 *
 *   goods receipt         Dr Inventory                 Cr GRNI
 *   supplier invoice      Dr GRNI (at order cost)      Cr Accounts payable
 *                         Dr/Cr Purchase price variance, Dr Input tax, Dr Other charges
 *   stock write-off/count Dr/Cr Inventory adjustments  Cr/Dr Inventory
 *   supplier return       Dr Returns awaiting credit   Cr Inventory
 */
class AccountingBridge
{
    public function __construct(private readonly GeneralLedger $ledger) {}

    public function goodsReceipt(GoodsReceipt $grn): ?JournalEntry
    {
        $value = '0';
        foreach ($grn->lines()->get() as $line) {
            $poLine = PurchaseOrderLine::query()->find($line->purchase_order_line_id);
            $value = Money::add($value, Money::mul((string) $line->base_quantity, $this->orderCostPerBase($poLine)));
        }
        if (bccomp($value, '0', 4) === 0) {
            return null;
        }

        return $this->ledger->post($grn->received_at->toDateString(), 'goods_receipt', $grn->id, "Goods receipt {$grn->number}", [
            ['account' => 'inventory', 'debit' => $value],
            ['account' => 'grni', 'credit' => $value, 'party_type' => 'supplier', 'party_id' => $grn->supplier_id],
        ]);
    }

    public function supplierInvoice(SupplierInvoice $invoice): JournalEntry
    {
        $lines = [];
        foreach ($invoice->lines()->get() as $line) {
            if ($line->purchase_order_line_id !== null) {
                $poLine = PurchaseOrderLine::query()->find($line->purchase_order_line_id);
                // Clear GRNI exactly as it was credited at receipt; rounding lands in price variance.
                $atOrderCost = Money::mul((string) $line->base_quantity, $this->orderCostPerBase($poLine));
                $variance = bcsub((string) $line->line_subtotal, $atOrderCost, 4);
                $lines[] = ['account' => 'grni', 'debit' => $atOrderCost, 'memo' => "PO line {$poLine->id}"];
                if (bccomp($variance, '0', 4) > 0) {
                    $lines[] = ['account' => 'purchase_price_variance', 'debit' => $variance];
                } elseif (bccomp($variance, '0', 4) < 0) {
                    $lines[] = ['account' => 'purchase_price_variance', 'credit' => bcmul($variance, '-1', 4)];
                }
            } else {
                $lines[] = ['account' => 'other_expenses', 'debit' => (string) $line->line_subtotal, 'memo' => $line->description];
            }
        }
        $lines[] = ['account' => 'input_tax', 'debit' => (string) $invoice->tax_total];
        $lines[] = ['account' => 'accounts_payable', 'credit' => (string) $invoice->total, 'party_type' => 'supplier', 'party_id' => $invoice->supplier_id];

        return $this->ledger->post($invoice->invoice_date->toDateString(), 'supplier_invoice', $invoice->id,
            "Supplier invoice {$invoice->supplier_invoice_number} ({$invoice->number})", $lines, $invoice->currency_code);
    }

    /**
     * @param  list<array{batch_id: string, quantity: string}>  $deltas  signed base-unit changes
     */
    public function stockAdjustment(string $sourceType, string $sourceId, string $description, array $deltas): ?JournalEntry
    {
        $value = $this->value($deltas);
        if (bccomp($value, '0', 4) === 0) {
            return null;
        }
        $gain = bccomp($value, '0', 4) > 0;
        $abs = $gain ? $value : bcmul($value, '-1', 4);

        return $this->ledger->post(today()->toDateString(), $sourceType, $sourceId, $description, $gain
            ? [['account' => 'inventory', 'debit' => $abs], ['account' => 'inventory_adjustment', 'credit' => $abs]]
            : [['account' => 'inventory_adjustment', 'debit' => $abs], ['account' => 'inventory', 'credit' => $abs]]);
    }

    /**
     * Receipts outside a purchase order: opening balances are funded by equity;
     * other direct receipts (found stock, customer returns) by inventory adjustments.
     *
     * @param  list<array{batch_id: string, quantity: string}>  $deltas
     */
    public function directReceipt(string $postingId, string $reasonCode, array $deltas): ?JournalEntry
    {
        $value = $this->value($deltas);
        if (bccomp($value, '0', 4) === 0) {
            return null;
        }

        return $this->ledger->post(today()->toDateString(), 'stock_receipt', $postingId, "Stock receipt ({$reasonCode})", [
            ['account' => 'inventory', 'debit' => $value],
            ['account' => $reasonCode === 'opening_balance' ? 'equity' : 'inventory_adjustment', 'credit' => $value],
        ]);
    }

    /** Cost of goods delivered, at batch cost:  Dr COGS  Cr Inventory. */
    public function deliveryCost(string $sourceType, string $sourceId, string $description, array $deltas, string $date): ?JournalEntry
    {
        $value = $this->value($deltas);
        if (bccomp($value, '0', 4) === 0) {
            return null;
        }

        return $this->ledger->post($date, $sourceType, $sourceId, $description, [
            ['account' => 'cogs', 'debit' => $value],
            ['account' => 'inventory', 'credit' => $value],
        ]);
    }

    /** Dr Accounts receivable (total)  Cr Sales revenue (net of discount)  Cr Output tax. */
    public function salesInvoice(SalesInvoice $invoice): JournalEntry
    {
        $net = bcsub((string) $invoice->subtotal, (string) $invoice->discount_total, 4);

        return $this->ledger->post($invoice->invoice_date->toDateString(), 'sales_invoice', $invoice->id, "Sales invoice {$invoice->number}", [
            ['account' => 'accounts_receivable', 'debit' => (string) $invoice->total, 'party_type' => 'customer', 'party_id' => $invoice->customer_id],
            ['account' => 'sales_revenue', 'credit' => $net],
            ['account' => 'output_tax', 'credit' => (string) $invoice->tax_total],
        ], $invoice->currency_code);
    }

    /**
     * Dr Sales returns  Dr Output tax  Cr Accounts receivable; restocked goods: Dr Inventory  Cr COGS.
     *
     * @param  list<array{batch_id: string, quantity: string}>  $restocked
     */
    public function creditNote(CreditNote $note, array $restocked): JournalEntry
    {
        $lines = [
            ['account' => 'sales_returns', 'debit' => (string) $note->subtotal],
            ['account' => 'output_tax', 'debit' => (string) $note->tax_total],
            ['account' => 'accounts_receivable', 'credit' => (string) $note->total, 'party_type' => 'customer', 'party_id' => $note->customer_id],
        ];
        $value = $this->value($restocked);
        if (bccomp($value, '0', 4) > 0) {
            $lines[] = ['account' => 'inventory', 'debit' => $value];
            $lines[] = ['account' => 'cogs', 'credit' => $value];
        }

        return $this->ledger->post($note->credit_date->toDateString(), 'credit_note', $note->id, "Credit note {$note->number}", $lines, $note->currency_code);
    }

    public function supplierReturn(SupplierReturn $return): ?JournalEntry
    {
        $value = bcmul($this->value($return->lines()->get()->map(fn ($l) => ['batch_id' => $l->batch_id, 'quantity' => (string) $l->base_quantity])->all()), '1', 4);
        if (bccomp($value, '0', 4) === 0) {
            return null;
        }

        return $this->ledger->post(today()->toDateString(), 'supplier_return', $return->id, "Supplier return {$return->number}", [
            ['account' => 'returns_pending_credit', 'debit' => $value, 'party_type' => 'supplier', 'party_id' => $return->supplier_id],
            ['account' => 'inventory', 'credit' => $value],
        ]);
    }

    /** The same per-base-unit cost StockReceiptService records on the batch. */
    private function orderCostPerBase(PurchaseOrderLine $poLine): string
    {
        $basePerUnit = bcdiv((string) $poLine->base_quantity, (string) $poLine->quantity, 8);

        return bcdiv((string) $poLine->unit_price, $basePerUnit, 4);
    }

    /** Signed value at batch cost; uncosted batches contribute nothing. */
    private function value(array $deltas): string
    {
        $costs = Batch::query()->whereIn('id', array_column($deltas, 'batch_id'))->pluck('unit_cost', 'id');
        $value = '0';
        foreach ($deltas as $d) {
            if (($cost = $costs[$d['batch_id']] ?? null) !== null) {
                $value = Money::add($value, Money::mul((string) $d['quantity'], (string) $cost));
            }
        }

        return $value;
    }
}

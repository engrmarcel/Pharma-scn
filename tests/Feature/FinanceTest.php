<?php

namespace Tests\Feature;

use App\Domain\Finance\Models\Account;
use App\Domain\Finance\Models\JournalEntry;
use App\Domain\Inventory\Models\Batch;
use App\Domain\Parties\Models\Supplier;
use LogicException;
use Tests\Concerns\InteractsWithInventory;
use Tests\FeatureTestCase;

class FinanceTest extends FeatureTestCase
{
    use InteractsWithInventory;

    private function idem(): array
    {
        return ['Idempotency-Key' => 'k-'.bin2hex(random_bytes(8))];
    }

    /** Purchase 10 packs at 2,000 (+7.5% tax), receive them, return [actors..., po, invoice id]. */
    private function purchased(string $invoicePrice = '2000'): array
    {
        [$owner, $org, $product, $main] = $this->stockedOrganization();
        [$finance] = $this->organizationMember(['finance_manager'], organization: $org);
        $supplier = $this->inTenant($org, fn () => Supplier::query()->create(['code' => 'S1', 'name' => 'Acme', 'type' => 'distributor', 'currency_code' => 'NGN', 'payment_terms_days' => 30]));

        $this->actingAsApi($owner, $org);
        $po = $this->api('POST', 'purchase-orders', ['supplier_id' => $supplier->id, 'warehouse_id' => $main->id, 'lines' => [
            ['product_id' => $product->id, 'package_level_code' => 'pack', 'quantity' => '10', 'unit_price' => '2000', 'tax_rate' => '7.5'],
        ]])->json('data');
        $this->api('POST', "purchase-orders/{$po['id']}/submit");
        $this->actingAsApi($finance, $org)->api('POST', "purchase-orders/{$po['id']}/approve")->assertOk();
        $this->actingAsApi($owner, $org)->api('POST', 'goods-receipts', ['purchase_order_id' => $po['id'], 'lines' => [[
            'purchase_order_line_id' => $po['lines'][0]['id'], 'batch_number' => 'F1', 'expiry_date' => now()->addYear()->toDateString(), 'quantity' => '10',
        ]]], $this->idem())->assertCreated();

        $invoice = $this->api('POST', 'supplier-invoices', [
            'supplier_id' => $supplier->id, 'purchase_order_id' => $po['id'], 'supplier_invoice_number' => 'INV-1', 'invoice_date' => today()->toDateString(),
            'lines' => [['purchase_order_line_id' => $po['lines'][0]['id'], 'quantity' => '10', 'unit_price' => $invoicePrice, 'tax_rate' => '7.5']],
        ], $this->idem())->assertCreated()->json('data.id');
        $this->actingAsApi($finance, $org)->api('POST', "supplier-invoices/{$invoice}/approve")->assertOk();

        return [$owner, $org, $product, $main, $finance, $supplier, $po, $invoice];
    }

    private function balances(): array
    {
        return collect($this->api('GET', 'finance/reports/trial-balance')->assertOk()->json('data.accounts'))->pluck('balance', 'code')->all();
    }

    public function test_purchasing_cycle_posts_balanced_entries_and_books_match_stock(): void
    {
        [, $org, , , $finance] = $this->purchased('2100'); // invoiced 100 per pack above the order price

        $this->actingAsApi($finance, $org);
        $tb = $this->api('GET', 'finance/reports/trial-balance')->assertOk()->assertJsonPath('data.balanced', true)->json('data');
        $b = collect($tb['accounts'])->pluck('balance', 'code');

        $this->assertSame('20000.0000', $b['1200'], 'inventory at order cost');
        $this->assertSame('0.0000', $b['2200'], 'GRNI cleared by the invoice');
        $this->assertSame('1000.0000', $b['5200'], 'price variance 10 × 100');
        $this->assertSame('1575.0000', $b['1300'], 'input tax 7.5% of 21,000');
        $this->assertSame('22575.0000', $b['2000'], 'payable = invoice total');

        // The books agree with the stock sub-ledger.
        $this->api('GET', 'inventory/valuation')->assertJsonPath('data.0.value', '20000.0000');

        $sources = JournalEntry::acrossTenants()->where('organization_id', $org->id)->pluck('source_type')->all();
        $this->assertEqualsCanonicalizing(['goods_receipt', 'supplier_invoice'], $sources);
    }

    public function test_supplier_payments_allocate_and_reduce_payables(): void
    {
        [, $org, , , $finance, $supplier, , $invoice] = $this->purchased();
        $this->actingAsApi($finance, $org);
        $pay = fn (string $amount, array $alloc) => $this->api('POST', 'finance/payments/supplier', [
            'supplier_id' => $supplier->id, 'amount' => $amount, 'method' => 'bank_transfer', 'payment_date' => today()->toDateString(),
            'allocations' => $alloc,
        ], $this->idem());

        // Invoice total: 20,000 + 1,500 tax = 21,500.
        $pay('30000', [['supplier_invoice_id' => $invoice, 'amount' => '30000']])->assertUnprocessable()->assertJsonPath('error.code', 'allocation_exceeds_balance');
        $pay('1000', [['supplier_invoice_id' => $invoice, 'amount' => '2000']])->assertUnprocessable()->assertJsonPath('error.code', 'allocation_exceeds_payment');

        $pay('10000', [['supplier_invoice_id' => $invoice, 'amount' => '10000']])->assertCreated()->assertJsonPath('data.number', 'PAY-000001');
        $this->assertDatabaseHas('supplier_invoices', ['id' => $invoice, 'payment_status' => 'partial', 'amount_paid' => '10000.0000']);

        $aging = $this->api('GET', 'finance/reports/payables-aging')->assertOk()->json('data');
        $this->assertSame('11500.0000', $aging['buckets']['current']);

        $payment = $pay('11500', [])->assertCreated()->json('data.id');
        $this->api('POST', "finance/payments/{$payment}/allocations", ['allocations' => [['supplier_invoice_id' => $invoice, 'amount' => '11500']]], $this->idem())->assertOk();
        $this->assertDatabaseHas('supplier_invoices', ['id' => $invoice, 'payment_status' => 'paid']);

        $b = $this->balances();
        $this->assertSame('0.0000', $b['2000'], 'payable settled');
        $this->assertSame('-21500.0000', $b['1010'], 'bank paid out (no opening balance in this test)');
    }

    public function test_stock_write_offs_and_counts_post_at_batch_cost(): void
    {
        [$owner, $org, , $main, $finance] = $this->purchased();
        $batch = Batch::acrossTenants()->where('organization_id', $org->id)->firstOrFail();
        $this->assertSame('100.0000', (string) $batch->unit_cost);

        $this->actingAsApi($owner, $org)->api('POST', 'stock-adjustments', ['warehouse_id' => $main->id, 'reason_code' => 'damage', 'lines' => [['batch_id' => $batch->id, 'quantity_delta' => '-3']]], $this->idem())->assertCreated();
        $count = $this->api('POST', 'stock-counts', ['warehouse_id' => $main->id])->json('data.id');
        $this->api('PUT', "stock-counts/{$count}/lines", ['lines' => [['batch_id' => $batch->id, 'counted_quantity' => '199']]]);
        $this->api('POST', "stock-counts/{$count}/post")->assertOk(); // 197 on hand → +2 found

        $b = $this->actingAsApi($finance, $org)->balances();
        $this->assertSame('100.0000', $b['5100'], 'write-off 300 less count gain 200');
        $this->assertSame('19900.0000', $b['1200']);
        $this->api('GET', 'inventory/valuation')->assertJsonPath('data.0.value', '19900.0000');
    }

    public function test_manual_journals_must_balance_and_can_only_be_reversed(): void
    {
        [$owner, $org] = $this->organizationMember();
        $this->actingAsApi($owner, $org);
        $accounts = collect($this->api('GET', 'finance/accounts')->assertOk()->json('data'))->pluck('id', 'system_key');

        $this->api('POST', 'finance/journal-entries', ['entry_date' => today()->toDateString(), 'description' => 'Bad', 'lines' => [
            ['account_id' => $accounts['bank'], 'debit' => '100'], ['account_id' => $accounts['equity'], 'credit' => '99'],
        ]], $this->idem())->assertUnprocessable()->assertJsonPath('error.code', 'journal_unbalanced');

        $this->api('POST', 'finance/journal-entries', ['entry_date' => today()->toDateString(), 'description' => 'Both sides', 'lines' => [
            ['account_id' => $accounts['bank'], 'debit' => '100', 'credit' => '100'], ['account_id' => $accounts['equity'], 'credit' => '0'],
        ]], $this->idem())->assertUnprocessable();

        $entry = $this->api('POST', 'finance/journal-entries', ['entry_date' => today()->toDateString(), 'description' => 'Capital injection', 'lines' => [
            ['account_id' => $accounts['bank'], 'debit' => '500000'], ['account_id' => $accounts['equity'], 'credit' => '500000'],
        ]], $this->idem())->assertCreated()->json('data.id');

        $this->api('POST', "finance/journal-entries/{$entry}/reverse", ['reason' => 'Posted to wrong account'], $this->idem())->assertCreated()->assertJsonPath('data.reversal_of_id', $entry);
        $this->api('POST', "finance/journal-entries/{$entry}/reverse", ['reason' => 'again'], $this->idem())->assertStatus(409);
        $this->assertSame('0.0000', $this->balances()['1010']);

        $this->expectException(LogicException::class);
        JournalEntry::acrossTenants()->findOrFail($entry)->update(['description' => 'tampered']);
    }

    public function test_closed_periods_block_all_postings_including_operational_ones(): void
    {
        [$owner, $org, $product, $main] = $this->stockedOrganization();
        [$accountant] = $this->organizationMember(['accountant'], organization: $org);
        $this->actingAsApi($owner, $org);
        $accounts = collect($this->api('GET', 'finance/accounts')->json('data'))->pluck('id', 'system_key');
        $this->api('POST', 'finance/journal-entries', ['entry_date' => today()->toDateString(), 'description' => 'Open month', 'lines' => [
            ['account_id' => $accounts['bank'], 'debit' => '1'], ['account_id' => $accounts['equity'], 'credit' => '1'],
        ]], $this->idem())->assertCreated();
        $period = $this->api('GET', 'finance/periods')->json('data.0.id');

        $this->actingAsApi($accountant, $org)->api('POST', "finance/periods/{$period}/close")->assertForbidden();
        $this->actingAsApi($owner, $org)->api('POST', "finance/periods/{$period}/close")->assertOk()->assertJsonPath('data.status', 'closed');

        // A costed receipt in the closed month cannot post its journal, so the whole receipt is refused.
        $this->api('POST', 'inventory/receipts', ['warehouse_id' => $main->id, 'reason_code' => 'opening_balance', 'lines' => [[
            'product_id' => $product->id, 'batch_number' => 'X', 'expiry_date' => now()->addYear()->toDateString(), 'quantity' => '5', 'unit_cost' => '10',
        ]]], $this->idem())->assertStatus(409)->assertJsonPath('error.code', 'period_closed');
        $this->api('GET', 'inventory/balances')->assertJsonCount(0, 'data');

        $this->api('POST', "finance/periods/{$period}/reopen")->assertUnprocessable();
        $this->api('POST', "finance/periods/{$period}/reopen", ['reason' => 'Late supplier documents'])->assertOk()->assertJsonPath('data.status', 'open');
    }

    public function test_opening_balance_receipts_are_funded_by_equity_and_statements_balance(): void
    {
        [$owner, $org, $product, $main] = $this->stockedOrganization();
        $this->actingAsApi($owner, $org)->api('POST', 'inventory/receipts', ['warehouse_id' => $main->id, 'reason_code' => 'opening_balance', 'lines' => [[
            'product_id' => $product->id, 'batch_number' => 'OPEN', 'expiry_date' => now()->addYear()->toDateString(), 'quantity' => '50', 'unit_cost' => '40',
        ]]], $this->idem())->assertCreated();

        $b = $this->balances();
        $this->assertSame('2000.0000', $b['1200']);
        $this->assertSame('2000.0000', $b['3000']);
        $this->api('GET', 'finance/reports/balance-sheet')->assertOk()->assertJsonPath('data.balanced', true)->assertJsonPath('data.assets', '2000.0000');
        $this->api('GET', 'finance/reports/profit-and-loss')->assertOk()->assertJsonPath('data.net_income', '0.0000');
    }

    public function test_finance_access_and_isolation(): void
    {
        [$owner, $org] = $this->organizationMember();
        [$ownerB, $orgB] = $this->organizationMember();
        [$cashier] = $this->organizationMember(['pos_cashier'], organization: $org);
        $foreignAccount = $this->actingAsApi($ownerB, $orgB)->api('GET', 'finance/accounts')->json('data.0.id');

        $this->actingAsApi($cashier, $org)->api('GET', 'finance/reports/trial-balance')->assertForbidden();

        $this->actingAsApi($owner, $org);
        $own = collect($this->api('GET', 'finance/accounts')->json('data'))->pluck('id', 'system_key');
        $this->api('POST', 'finance/journal-entries', ['entry_date' => today()->toDateString(), 'description' => 'x', 'lines' => [
            ['account_id' => $own['bank'], 'debit' => '1'], ['account_id' => $foreignAccount, 'credit' => '1'],
        ]], $this->idem())->assertUnprocessable()->assertJsonValidationErrors('lines.1.account_id');

        $this->api('PATCH', "finance/accounts/{$own['inventory']}", ['status' => 'inactive'])->assertStatus(409)->assertJsonPath('error.code', 'system_account_required');
        $this->api('POST', 'finance/accounts', ['code' => '6100', 'name' => 'Rent', 'type' => 'expense'])->assertCreated();
        $this->assertSame(1, Account::acrossTenants()->where('organization_id', $org->id)->where('code', '6100')->count());
    }
}

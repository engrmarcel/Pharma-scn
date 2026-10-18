<?php

namespace Tests\Feature;

use App\Domain\Catalogue\Models\OrganizationProduct;
use App\Domain\Inventory\Models\InventoryBalance;
use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Parties\Models\Customer;
use App\Domain\Sales\Models\SalesOrder;
use Tests\Concerns\InteractsWithInventory;
use Tests\FeatureTestCase;

class SalesTest extends FeatureTestCase
{
    use InteractsWithInventory;

    private function idem(): array
    {
        return ['Idempotency-Key' => 'k-'.bin2hex(random_bytes(8))];
    }

    /** Stocked organization with a price list (3,000 per pack of 20) and a customer. */
    private function selling(array $customerExtra = []): array
    {
        [$owner, $org, $product, $main] = $this->stockedOrganization();
        [$manager] = $this->organizationMember(['sales_manager'], organization: $org);
        $this->receive($org, $main, $product, 'S-LATE', '100', '+2 years', ['unit_cost' => '50']);
        $this->receive($org, $main, $product, 'S-EARLY', '30', '+3 months', ['unit_cost' => '40']);
        $customer = $this->inTenant($org, fn () => Customer::query()->create(['code' => 'C1', 'name' => 'Lekki Clinic', 'type' => 'clinic', 'currency_code' => 'NGN', 'payment_terms_days' => 14] + $customerExtra));

        $this->actingAsApi($owner, $org)->api('POST', 'price-lists', ['code' => 'STD', 'name' => 'Standard', 'is_default' => true,
            'items' => [['product_id' => $product->id, 'package_level_code' => 'pack', 'unit_price' => '3000']]])->assertCreated();

        return [$owner, $org, $product, $main, $customer, $manager];
    }

    private function order($customer, $main, $product, string $packs, array $extra = []): array
    {
        return $this->api('POST', 'sales-orders', ['customer_id' => $customer->id, 'warehouse_id' => $main->id, 'lines' => [
            ['product_id' => $product->id, 'package_level_code' => 'pack', 'quantity' => $packs] + $extra,
        ]], $this->idem())->assertCreated()->json('data');
    }

    private function balances(): array
    {
        return collect($this->api('GET', 'finance/reports/trial-balance')->assertOk()->assertJsonPath('data.balanced', true)->json('data.accounts'))->pluck('balance', 'code')->all();
    }

    public function test_order_to_cash_cycle_with_fefo_cogs_invoice_payment_and_statement(): void
    {
        [$owner, $org, $product, $main, $customer] = $this->selling();

        $so = $this->order($customer, $main, $product, '3');
        $this->assertSame('3000.0000', $so['lines'][0]['unit_price']);
        $this->assertSame('EXEMPT', $so['lines'][0]['tax_category_code'], 'country default category for medicines');
        $this->assertSame('9000.0000', $so['total']);

        $this->api('POST', "sales-orders/{$so['id']}/confirm")->assertOk()->assertJsonPath('data.status', 'confirmed');
        // 60 base units reserved FEFO: all 30 of the early batch, 30 of the late one.
        $reserved = $this->inTenant($org, fn () => StockReservation::query()->where('status', 'active')->get()->pluck('quantity', 'batch_id')->map(fn ($q) => (string) $q)->sort()->values()->all());
        $this->assertSame(['30.0000', '30.0000'], $reserved);

        $line = $so['lines'][0]['id'];
        $this->api('POST', "sales-orders/{$so['id']}/deliveries", ['lines' => [['sales_order_line_id' => $line, 'quantity' => '2']]], $this->idem())
            ->assertCreated()->assertJsonPath('data.number', 'DN-000001');
        $this->assertSame('partially_delivered', SalesOrder::acrossTenants()->find($so['id'])->status);

        // 40 units shipped: 30 early @40 + 10 late @50 = 1,700 cost of goods sold.
        $this->assertSame('1700.0000', $this->balances()['5000']);

        $invoice = $this->api('POST', "sales-orders/{$so['id']}/invoice", [], $this->idem())->assertCreated()
            ->assertJsonPath('data.total', '6000.0000')->assertJsonPath('data.lines.0.quantity', '2.0000')
            ->assertJsonPath('data.due_date', fn ($d) => str_starts_with($d, today()->addDays(14)->toDateString()))->json('data.id');
        $this->api('POST', "sales-orders/{$so['id']}/invoice", [], $this->idem())->assertStatus(409)->assertJsonPath('error.code', 'nothing_to_invoice');

        $b = $this->balances();
        $this->assertSame('6000.0000', $b['1100']);
        $this->assertSame('6000.0000', $b['4000']);

        $this->api('GET', 'sales/receivables-aging')->assertJsonPath('data.buckets.current', '6000.0000');

        $this->api('POST', 'customer-payments', ['customer_id' => $customer->id, 'amount' => '6000', 'method' => 'bank_transfer', 'payment_date' => today()->toDateString(),
            'allocations' => [['sales_invoice_id' => $invoice, 'amount' => '6000']]], $this->idem())->assertCreated()->assertJsonPath('data.number', 'RCT-000001');
        $this->api('GET', "sales-invoices/{$invoice}")->assertJsonPath('data.payment_status', 'paid')->assertJsonPath('data.open_balance', '0.0000');

        $statement = $this->api('GET', "customers/{$customer->id}/statement")->assertOk()->json('data');
        $this->assertSame('0.0000', $statement['closing_balance']);
        $this->assertCount(2, $statement['lines']);

        // Deliver and invoice the rest.
        $this->api('POST', "sales-orders/{$so['id']}/deliveries", ['lines' => [['sales_order_line_id' => $line, 'quantity' => '1']]], $this->idem())->assertCreated();
        $this->assertSame('delivered', SalesOrder::acrossTenants()->find($so['id'])->status);
        $this->api('POST', "sales-orders/{$so['id']}/invoice", [], $this->idem())->assertCreated()->assertJsonPath('data.total', '3000.0000');
        $this->assertSame('0', $this->inTenant($org, fn () => (string) StockReservation::query()->where('status', 'active')->count()));
    }

    public function test_discount_and_credit_limit_hold_orders_for_independent_approval(): void
    {
        [$owner, $org, $product, $main, $customer, $manager] = $this->selling(['credit_limit' => '5000']);

        $discounted = $this->order($customer, $main, $product, '1', ['discount_percent' => '10']);
        $this->assertSame('2700.0000', $discounted['total']);
        $this->api('POST', "sales-orders/{$discounted['id']}/confirm")->assertOk()->assertJsonPath('data.status', 'pending_approval')
            ->assertJsonPath('data.approval_reasons.0.reason', 'discount_above_limit');
        $this->api('POST', "sales-orders/{$discounted['id']}/approve")->assertForbidden()->assertJsonPath('error.code', 'self_approval_not_allowed');
        $this->actingAsApi($manager, $org)->api('POST', "sales-orders/{$discounted['id']}/approve")->assertOk()->assertJsonPath('data.status', 'confirmed');

        $big = $this->actingAsApi($owner, $org)->order($customer, $main, $product, '2'); // 6,000 > 5,000 credit limit
        $this->api('POST', "sales-orders/{$big['id']}/confirm")->assertOk()->assertJsonPath('data.status', 'pending_approval')
            ->assertJsonPath('data.approval_reasons.0.reason', 'credit_limit_exceeded');
        $this->actingAsApi($manager, $org)->api('POST', "sales-orders/{$big['id']}/reject", ['reason' => 'Collect overdue balance first'])->assertOk()->assertJsonPath('data.status', 'draft');
        $this->assertSame(0, $this->inTenant($org, fn () => StockReservation::query()->where('reference_id', $big['lines'][0]['id'])->count()), 'rejected orders reserve nothing');
    }

    public function test_price_overrides_need_permission_and_quoted_prices_are_honoured(): void
    {
        [$owner, $org, $product, $main, $customer] = $this->selling();
        [$rep] = $this->organizationMember(['sales_representative'], organization: $org);
        // Representatives only reach customers assigned to them (field-force assignments).
        $this->inTenant($org, fn () => $customer->forceFill(['assigned_representative_id' => $rep->id])->save());

        $this->actingAsApi($rep, $org)->api('POST', 'sales-orders', ['customer_id' => $customer->id, 'warehouse_id' => $main->id, 'lines' => [
            ['product_id' => $product->id, 'package_level_code' => 'pack', 'quantity' => '1', 'unit_price' => '2500'],
        ]], $this->idem())->assertForbidden()->assertJsonPath('error.code', 'price_override_not_allowed');

        $quote = $this->actingAsApi($owner, $org)->api('POST', 'quotations', ['customer_id' => $customer->id, 'warehouse_id' => $main->id, 'lines' => [
            ['product_id' => $product->id, 'package_level_code' => 'pack', 'quantity' => '1', 'unit_price' => '2800'],
        ]])->assertCreated()->assertJsonPath('data.number', 'QT-000001')->json('data.id');

        // The list price changes after quoting; the rep converts and the quote holds.
        $list = $this->api('GET', 'price-lists')->json('data.0.id');
        $this->api('PUT', "price-lists/{$list}/items", ['items' => [['product_id' => $product->id, 'package_level_code' => 'pack', 'unit_price' => '3500']]])->assertOk();
        $this->actingAsApi($rep, $org)->api('POST', "quotations/{$quote}/convert", [], $this->idem())->assertCreated()->assertJsonPath('data.lines.0.unit_price', '2800.0000');
    }

    public function test_expired_quotations_cannot_be_converted(): void
    {
        [$owner, $org, $product, $main, $customer] = $this->selling();
        $quote = $this->api('POST', 'quotations', ['customer_id' => $customer->id, 'warehouse_id' => $main->id, 'valid_until' => today()->addDay()->toDateString(), 'lines' => [
            ['product_id' => $product->id, 'package_level_code' => 'pack', 'quantity' => '1'],
        ]])->json('data.id');
        $this->travel(3)->days();

        $this->api('POST', "quotations/{$quote}/convert", [], $this->idem())->assertStatus(409)->assertJsonPath('error.code', 'quotation_expired');
    }

    public function test_tax_is_charged_on_the_discounted_amount_at_the_effective_rate(): void
    {
        [$owner, $org, $product, $main, $customer] = $this->selling();
        $this->inTenant($org, fn () => OrganizationProduct::query()->where('product_id', $product->id)->update(['tax_category_code' => 'VAT_STANDARD']));
        $this->api('PATCH', 'sales/settings', ['max_discount_percent' => '10'])->assertOk();

        $so = $this->order($customer, $main, $product, '3', ['discount_percent' => '5']);
        // 9,000 − 5% = 8,550; VAT 7.5% = 641.25
        $this->assertSame('9000.0000', $so['subtotal']);
        $this->assertSame('450.0000', $so['discount_total']);
        $this->assertSame('641.2500', $so['tax_total']);
        $this->assertSame('9191.2500', $so['total']);
        $this->api('POST', "sales-orders/{$so['id']}/confirm")->assertJsonPath('data.status', 'confirmed');
    }

    public function test_credit_note_with_return_restocks_quarantined_by_default(): void
    {
        [$owner, $org, $product, $main, $customer] = $this->selling();
        [$supervisor] = $this->organizationMember(['pos_supervisor'], organization: $org);
        $so = $this->order($customer, $main, $product, '1');
        $this->api('POST', "sales-orders/{$so['id']}/confirm");
        $delivery = $this->api('POST', "sales-orders/{$so['id']}/deliveries", ['lines' => [['sales_order_line_id' => $so['lines'][0]['id'], 'quantity' => '1']]], $this->idem())->json('data');
        $invoice = $this->api('POST', "sales-orders/{$so['id']}/invoice", [], $this->idem())->json('data');
        $batch = $delivery['lines'][0]['batch_id'];
        $line = $invoice['lines'][0]['id'];

        $credit = fn (array $l, $as = null) => $this->actingAsApi($as ?? $owner, $org)->api('POST', "sales-invoices/{$invoice['id']}/credit-notes", [
            'reason_code' => 'return', 'return_warehouse_id' => $main->id, 'lines' => [$l + ['sales_invoice_line_id' => $line]],
        ], $this->idem());

        $credit(['quantity' => '2'])->assertUnprocessable()->assertJsonPath('error.code', 'credit_quantity_invalid');
        $credit(['quantity' => '0.5', 'batch_id' => $batch, 'restock_state' => 'available'], $supervisor)->assertForbidden()->assertJsonPath('error.code', 'restock_not_allowed');

        $credit(['quantity' => '0.5', 'batch_id' => $batch], $supervisor)->assertCreated()->assertJsonPath('data.number', 'CN-000001')->assertJsonPath('data.total', '1500.0000');

        // 10 units back in quarantine at batch cost 40 (FEFO shipped the early batch).
        $this->assertSame('10.0000', $this->inTenant($org, fn () => (string) InventoryBalance::query()->where('batch_id', $batch)->where('stock_state', 'quarantined')->value('quantity')));
        $this->actingAsApi($owner, $org);
        $b = $this->balances();
        $this->assertSame('-1500.0000', $b['4100'], 'sales returns (contra revenue)');
        $this->assertSame('1500.0000', $b['1100'], 'receivable reduced to 1,500');
        $this->assertSame('400.0000', $b['5000'], 'COGS 800 less 400 returned');
        $this->api('GET', "sales-invoices/{$invoice['id']}")->assertJsonPath('data.open_balance', '1500.0000')->assertJsonPath('data.amount_credited', '1500.0000');
    }

    public function test_confirmation_fails_cleanly_when_stock_is_short_and_cancel_releases_reservations(): void
    {
        [$owner, $org, $product, $main, $customer] = $this->selling();

        $tooMuch = $this->order($customer, $main, $product, '10'); // 200 units; 130 in stock
        $this->api('POST', "sales-orders/{$tooMuch['id']}/confirm")->assertStatus(409)->assertJsonPath('error.code', 'insufficient_stock');
        $this->assertSame('draft', SalesOrder::acrossTenants()->find($tooMuch['id'])->status);

        $ok = $this->order($customer, $main, $product, '2');
        $this->api('POST', "sales-orders/{$ok['id']}/confirm")->assertJsonPath('data.status', 'confirmed');
        $this->api('POST', "sales-orders/{$ok['id']}/cancel", ['reason' => 'Customer changed mind'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->api('GET', "inventory/stock-levels?product_id={$product->id}")->assertJsonPath('data.0.reserved', '0.0000');
    }

    public function test_customer_on_hold_and_tenant_isolation(): void
    {
        [$owner, $org, $product, $main, $customer] = $this->selling();
        [$ownerB, $orgB] = $this->stockedOrganization();
        $so = $this->order($customer, $main, $product, '1');

        $this->inTenant($org, fn () => $customer->forceFill(['status' => 'on_hold'])->save());
        $this->api('POST', "sales-orders/{$so['id']}/confirm")->assertStatus(409)->assertJsonPath('error.code', 'customer_not_active');

        $this->actingAsApi($ownerB, $orgB)->api('GET', "sales-orders/{$so['id']}")->assertNotFound();
        $this->api('POST', 'sales-orders', ['customer_id' => $customer->id, 'warehouse_id' => $main->id, 'lines' => [['product_id' => $product->id, 'quantity' => '1']]], $this->idem())
            ->assertUnprocessable()->assertJsonValidationErrors(['customer_id', 'warehouse_id']);
        $this->api('GET', 'sales-invoices')->assertJsonCount(0, 'data');
    }
}

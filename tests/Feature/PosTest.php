<?php

namespace Tests\Feature;

use App\Domain\Billing\Services\EntitlementService;
use App\Domain\Catalogue\Models\ProductClassification;
use App\Domain\Finance\Models\Payment;
use App\Domain\Inventory\Models\InventoryBalance;
use App\Domain\Pos\Models\PosSale;
use App\Domain\Pos\Payments\FakePaymentProvider;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithInventory;
use Tests\FeatureTestCase;

class PosTest extends FeatureTestCase
{
    use InteractsWithInventory;

    private function idem(): array
    {
        return ['Idempotency-Key' => 'k-'.bin2hex(random_bytes(8))];
    }

    /**
     * Pharmacy with stock (30 units expiring soon @40, 100 later @50), a price list
     * (3,000 per pack of 20), a terminal, a cashier with an open shift and a supervisor.
     */
    private function till(string $cashierRole = 'pos_cashier'): array
    {
        [$owner, $org, $product, $main] = $this->stockedOrganization();
        [$cashier] = $this->organizationMember([$cashierRole], organization: $org);
        [$supervisor] = $this->organizationMember(['pos_supervisor'], organization: $org);
        $early = $this->receive($org, $main, $product, 'P-EARLY', '30', '+3 months', ['unit_cost' => '40']);
        $late = $this->receive($org, $main, $product, 'P-LATE', '100', '+2 years', ['unit_cost' => '50']);

        $this->actingAsApi($owner, $org)->api('POST', 'price-lists', ['code' => 'STD', 'name' => 'Standard', 'is_default' => true,
            'items' => [['product_id' => $product->id, 'package_level_code' => 'pack', 'unit_price' => '3000']]])->assertCreated();
        $terminal = $this->api('POST', 'pos/terminals', ['warehouse_id' => $main->id, 'code' => 'T1', 'name' => 'Front counter'])->assertCreated()->json('data.id');

        $shift = $this->actingAsApi($cashier, $org)->api('POST', 'pos/shifts', ['pos_terminal_id' => $terminal, 'opening_float' => '5000'])->assertCreated()->json('data.id');

        return compact('owner', 'org', 'product', 'main', 'cashier', 'supervisor', 'early', 'late', 'terminal', 'shift');
    }

    /** Start a sale with `$packs` packs in the cart and check it out. */
    private function cartAndCheckout(array $t, string $packs = '2', array $line = []): array
    {
        $sale = $this->api('POST', 'pos/sales', ['pos_shift_id' => $t['shift'], 'client_transaction_id' => (string) Str::ulid()])->assertCreated()->json('data.id');
        $this->api('PUT', "pos/sales/{$sale}/lines", ['lines' => [['product_id' => $t['product']->id, 'package_level_code' => 'pack', 'quantity' => $packs] + $line]])->assertOk();
        $this->api('POST', "pos/sales/{$sale}/checkout")->assertOk()->assertJsonPath('data.status', 'awaiting_payment');

        return ['id' => $sale];
    }

    private function balances(array $t): array
    {
        return collect($this->actingAsApi($t['owner'], $t['org'])->api('GET', 'finance/reports/trial-balance')->assertJsonPath('data.balanced', true)->json('data.accounts'))->pluck('balance', 'code')->all();
    }

    public function test_cash_sale_completes_atomically_with_fefo_batches_invoice_payment_and_books(): void
    {
        $t = $this->till();
        $clientId = (string) Str::ulid();

        $sale = $this->api('POST', 'pos/sales', ['pos_shift_id' => $t['shift'], 'client_transaction_id' => $clientId])->assertCreated()->json('data.id');
        // A retried start (same client transaction id) returns the same sale.
        $this->api('POST', 'pos/sales', ['pos_shift_id' => $t['shift'], 'client_transaction_id' => $clientId])->assertOk()->assertJsonPath('data.id', $sale);

        $this->api('PUT', "pos/sales/{$sale}/lines", ['lines' => [['product_id' => $t['product']->id, 'package_level_code' => 'pack', 'quantity' => '2']]])
            ->assertOk()->assertJsonPath('data.total', '6000.0000');
        $this->api('POST', "pos/sales/{$sale}/checkout")->assertOk();
        $this->api('POST', "pos/sales/{$sale}/payments", ['method' => 'cash', 'amount' => '6000', 'tendered' => '10000'], $this->idem())
            ->assertCreated()->assertJsonPath('data.sale.status', 'completed')->assertJsonPath('data.sale.change_due', '4000.0000');

        // FEFO: 30 early + 10 late.
        $this->assertSame(['0.0000', '0.0000'], $this->balance($t['org'], $t['main'], $t['early']));
        $this->assertSame(['90.0000', '0.0000'], $this->balance($t['org'], $t['main'], $t['late']));

        $receipt = $this->api('GET', "pos/sales/{$sale}/receipt")->assertOk()->json('data');
        $this->assertSame(['P-EARLY', 'P-LATE'], collect($receipt['lines'][0]['batches'])->pluck('batch_number')->all());
        $this->assertSame('4000.0000', $receipt['totals']['change']);
        $this->assertStringStartsWith('INV-', $receipt['invoice_number']);

        $this->api('GET', 'pos/shifts/current')->assertJsonPath('data.drawer', '11000.0000');

        $b = $this->balances($t);
        $this->assertSame('6000.0000', $b['4000'], 'revenue');
        $this->assertSame('6000.0000', $b['1000'], 'cash');
        $this->assertSame('0.0000', $b['1100'], 'receivable settled');
        $this->assertSame('1700.0000', $b['5000'], 'COGS 30×40 + 10×50');
    }

    public function test_shift_close_variance_and_independent_review(): void
    {
        $t = $this->till();
        $sale = $this->cartAndCheckout($t, '1');
        $this->api('POST', "pos/sales/{$sale['id']}/payments", ['method' => 'cash', 'amount' => '3000'], $this->idem())->assertCreated();

        $this->api('POST', "pos/shifts/{$t['shift']}/cash-movements", ['amount' => '-500', 'reason' => 'Petty cash'], $this->idem())->assertForbidden();
        $this->actingAsApi($t['supervisor'], $t['org'])->api('POST', "pos/shifts/{$t['shift']}/cash-movements", ['amount' => '-500', 'reason' => 'Bank deposit run'], $this->idem())->assertCreated();

        $this->actingAsApi($t['cashier'], $t['org'])->api('POST', "pos/shifts/{$t['shift']}/close", ['counted_cash' => '7450'])
            ->assertOk()->assertJsonPath('data.expected_cash', '7500.0000')->assertJsonPath('data.variance', '-50.0000');
        $this->api('POST', "pos/shifts/{$t['shift']}/review", ['notes' => 'mine'])->assertForbidden();

        $this->actingAsApi($t['supervisor'], $t['org'])->api('POST', "pos/shifts/{$t['shift']}/review")->assertUnprocessable()->assertJsonPath('error.code', 'variance_explanation_required');
        $this->api('POST', "pos/shifts/{$t['shift']}/review", ['notes' => 'Counting error, recounted with cashier'])->assertOk()->assertJsonPath('data.status', 'reviewed');
    }

    public function test_electronic_payments_complete_only_on_a_verified_callback_and_duplicates_are_harmless(): void
    {
        $t = $this->till();
        $sale = $this->cartAndCheckout($t, '1');

        $pay = $this->api('POST', "pos/sales/{$sale['id']}/payments", ['method' => 'card', 'amount' => '3000', 'channel' => 'fake'], $this->idem())
            ->assertCreated()->assertJsonPath('data.payment.status', 'pending')->assertJsonPath('data.sale.status', 'awaiting_payment')->json('data');
        $reference = $pay['instructions']['reference'];

        $body = json_encode(['id' => 'evt_1', 'type' => 'payment.succeeded', 'reference' => $reference, 'amount' => '3000', 'currency' => 'NGN']);
        $this->call('POST', '/api/v1/payments/webhooks/fake', [], [], [], ['HTTP_X_FAKE_SIGNATURE' => 'forged', 'CONTENT_TYPE' => 'application/json'], $body)->assertStatus(401);
        $this->assertSame('awaiting_payment', PosSale::acrossTenants()->find($sale['id'])->status, 'the client cannot complete an electronic payment');

        $signed = ['HTTP_X_FAKE_SIGNATURE' => FakePaymentProvider::sign($body), 'CONTENT_TYPE' => 'application/json'];
        $this->call('POST', '/api/v1/payments/webhooks/fake', [], [], [], $signed, $body)->assertOk()->assertJsonPath('outcome', 'confirmed');
        $this->call('POST', '/api/v1/payments/webhooks/fake', [], [], [], $signed, $body)->assertOk()->assertJsonPath('outcome', 'duplicate');

        $this->assertSame('completed', PosSale::acrossTenants()->find($sale['id'])->status);
        $this->assertSame(1, Payment::acrossTenants()->where('organization_id', $t['org']->id)->where('direction', 'incoming')->count(), 'one receipt despite two callbacks');
        $this->assertSame('3000.0000', $this->balances($t)['1020'], 'card settlement account');
    }

    public function test_provider_amount_mismatch_fails_the_payment(): void
    {
        $t = $this->till();
        $sale = $this->cartAndCheckout($t, '1');
        $ref = $this->api('POST', "pos/sales/{$sale['id']}/payments", ['method' => 'card', 'amount' => '3000', 'channel' => 'fake'], $this->idem())->json('data.instructions.reference');

        $body = json_encode(['id' => 'evt_x', 'type' => 'payment.succeeded', 'reference' => $ref, 'amount' => '30', 'currency' => 'NGN']);
        $this->call('POST', '/api/v1/payments/webhooks/fake', [], [], [], ['HTTP_X_FAKE_SIGNATURE' => FakePaymentProvider::sign($body)], $body)->assertJsonPath('outcome', 'amount_mismatch');
        $this->assertSame('awaiting_payment', PosSale::acrossTenants()->find($sale['id'])->status);
    }

    public function test_standalone_terminal_payments_wait_for_another_persons_confirmation(): void
    {
        $t = $this->till();
        $sale = $this->cartAndCheckout($t, '1');

        $this->api('POST', "pos/sales/{$sale['id']}/payments", ['method' => 'card', 'amount' => '3000', 'channel' => 'manual_terminal'], $this->idem())
            ->assertUnprocessable()->assertJsonPath('error.code', 'approval_code_required');
        $payment = $this->api('POST', "pos/sales/{$sale['id']}/payments", ['method' => 'card', 'amount' => '3000', 'channel' => 'manual_terminal', 'approval_code' => 'A1B2C3'], $this->idem())
            ->assertCreated()->assertJsonPath('data.sale.status', 'awaiting_payment')->json('data.payment.id');

        $this->api('POST', "pos/payments/{$payment}/confirm")->assertForbidden();
        $this->actingAsApi($t['supervisor'], $t['org'])->api('GET', 'pos/payments/pending')->assertJsonCount(1, 'data');
        $this->api('POST', "pos/payments/{$payment}/confirm")->assertOk()->assertJsonPath('data.status', 'completed');
    }

    public function test_expired_scanned_batches_cannot_be_sold_and_last_units_cannot_be_sold_twice(): void
    {
        $t = $this->till();
        $expired = $this->receive($t['org'], $t['main'], $t['product'], 'P-OLD', '20', '+1 day', ['unit_cost' => '30']);
        $this->travel(2)->days();
        $this->actingAsApi($t['cashier'], $t['org']);

        $sale = $this->api('POST', 'pos/sales', ['pos_shift_id' => $t['shift'], 'client_transaction_id' => (string) Str::ulid()])->json('data.id');
        $this->api('PUT', "pos/sales/{$sale}/lines", ['lines' => [['product_id' => $t['product']->id, 'package_level_code' => 'pack', 'quantity' => '1', 'batch_id' => $expired->id]]])
            ->assertStatus(409)->assertJsonPath('error.code', 'batch_not_sellable');

        // 130 sellable units: one cart takes 6 packs (120); a second cart for 1 pack (20) cannot also have them.
        $this->cartAndCheckout($t, '6');
        $second = $this->api('POST', 'pos/sales', ['pos_shift_id' => $t['shift'], 'client_transaction_id' => (string) Str::ulid()])->json('data.id');
        $this->api('PUT', "pos/sales/{$second}/lines", ['lines' => [['product_id' => $t['product']->id, 'package_level_code' => 'pack', 'quantity' => '1']]]);
        $this->api('POST', "pos/sales/{$second}/checkout")->assertStatus(409)->assertJsonPath('error.code', 'insufficient_stock');
        $this->assertSame('open', PosSale::acrossTenants()->find($second)->status);
    }

    public function test_prescription_only_products_need_a_pharmacist_and_prescription_details(): void
    {
        $t = $this->till();
        $this->inTenant($t['org'], fn () => ProductClassification::query()->where('product_id', $t['product']->id)->delete());
        ProductClassification::query()->create(['product_id' => $t['product']->id, 'country_code' => 'NG', 'classification' => 'prescription_only']);
        [$pharmacist] = $this->organizationMember(['pharmacist'], organization: $t['org']);

        $sale = $this->api('POST', 'pos/sales', ['pos_shift_id' => $t['shift'], 'client_transaction_id' => (string) Str::ulid()])->json('data.id');
        $line = ['product_id' => $t['product']->id, 'package_level_code' => 'pack', 'quantity' => '1'];
        $this->api('PUT', "pos/sales/{$sale}/lines", ['lines' => [$line]])->assertForbidden()->assertJsonPath('error.code', 'dispensing_permission_required');

        // The pharmacist dispenses on their own shift.
        $this->api('POST', "pos/sales/{$sale}/cancel", ['reason' => 'Needs pharmacist'])->assertOk();
        $this->api('POST', "pos/shifts/{$t['shift']}/close", ['counted_cash' => '5000'])->assertOk();
        $shift = $this->actingAsApi($pharmacist, $t['org'])->api('POST', 'pos/shifts', ['pos_terminal_id' => $t['terminal'], 'opening_float' => '0'])->json('data.id');
        $sale = $this->api('POST', 'pos/sales', ['pos_shift_id' => $shift, 'client_transaction_id' => (string) Str::ulid()])->json('data.id');

        $this->api('PUT', "pos/sales/{$sale}/lines", ['lines' => [$line]])->assertUnprocessable()->assertJsonPath('error.code', 'prescription_required');
        $this->api('PUT', "pos/sales/{$sale}/lines", ['lines' => [$line + ['prescription' => ['reference' => 'RX-123', 'prescriber_name' => 'Dr A. Bello', 'prescribed_on' => today()->toDateString()]]]])
            ->assertOk()->assertJsonPath('data.lines.0.prescription.classification', 'prescription_only');
        $this->api('POST', "pos/sales/{$sale}/checkout");
        $this->api('POST', "pos/sales/{$sale}/payments", ['method' => 'cash', 'amount' => '3000'], $this->idem())->assertJsonPath('data.sale.status', 'completed');

        $receipt = $this->api('GET', "pos/sales/{$sale}/receipt")->json('data');
        $this->assertTrue($receipt['lines'][0]['dispensed_against_prescription']);
        $this->assertStringNotContainsString('Bello', json_encode($receipt), 'receipts carry no prescription details');
    }

    public function test_refunds_need_independent_approval_and_restock_to_quarantine(): void
    {
        $t = $this->till();
        $sale = $this->cartAndCheckout($t, '2');
        $this->api('POST', "pos/sales/{$sale['id']}/payments", ['method' => 'cash', 'amount' => '6000'], $this->idem());
        $line = $this->api('GET', "pos/sales/{$sale['id']}")->json('data.lines.0.id');

        $refund = $this->api('POST', "pos/sales/{$sale['id']}/refunds", ['reason' => 'Customer reaction', 'refund_method' => 'cash',
            'lines' => [['pos_sale_line_id' => $line, 'quantity' => '1']]], $this->idem())->assertCreated()->assertJsonPath('data.status', 'pending_approval')->json('data.id');

        $this->api('POST', "pos/refunds/{$refund}/approve")->assertForbidden();
        $this->actingAsApi($t['supervisor'], $t['org'])->api('POST', "pos/refunds/{$refund}/approve")->assertOk()
            ->assertJsonPath('data.status', 'approved')->assertJsonPath('data.amount', '3000.0000');

        // 20 units back to the batches they came from (10 late, then 10 early), held in quarantine.
        $quarantined = fn ($batch) => $this->inTenant($t['org'], fn () => (string) InventoryBalance::query()->where('batch_id', $batch->id)->where('stock_state', 'quarantined')->value('quantity'));
        $this->assertSame('10.0000', $quarantined($t['late']));
        $this->assertSame('10.0000', $quarantined($t['early']));
        $this->actingAsApi($t['cashier'], $t['org'])->api('GET', 'pos/shifts/current')->assertJsonPath('data.drawer', '8000.0000');

        // A supervisor cannot approve their own request.
        $own = $this->actingAsApi($t['supervisor'], $t['org'])->api('POST', "pos/sales/{$sale['id']}/refunds", ['reason' => 'x', 'refund_method' => 'cash',
            'lines' => [['pos_sale_line_id' => $line, 'quantity' => '1']]], $this->idem())->json('data.id');
        $this->api('POST', "pos/refunds/{$own}/approve")->assertForbidden()->assertJsonPath('error.code', 'self_approval_not_allowed');

        $b = $this->balances($t);
        $this->assertSame('0.0000', $b['1100'], 'receivable back to zero after refund payment');
        $this->assertSame('-3000.0000', $b['4100']);
    }

    public function test_cancelling_a_paid_sale_needs_a_supervisor_and_returns_the_cash(): void
    {
        $t = $this->till();
        $sale = $this->cartAndCheckout($t, '3');
        $this->api('POST', "pos/sales/{$sale['id']}/payments", ['method' => 'cash', 'amount' => '4000'], $this->idem())->assertJsonPath('data.sale.status', 'awaiting_payment');

        $this->api('POST', "pos/sales/{$sale['id']}/cancel", ['reason' => 'Customer left'])->assertForbidden();
        $this->actingAsApi($t['supervisor'], $t['org'])->api('POST', "pos/sales/{$sale['id']}/cancel", ['reason' => 'Customer left'])->assertOk()->assertJsonPath('data.status', 'cancelled');

        $this->actingAsApi($t['cashier'], $t['org'])->api('GET', 'pos/shifts/current')->assertJsonPath('data.drawer', '5000.0000');
        $this->api('GET', "inventory/stock-levels?product_id={$t['product']->id}")->assertJsonPath('data.0.reserved', '0.0000');
    }

    public function test_terminal_limits_cashier_boundaries_and_isolation(): void
    {
        $t = $this->till();
        app(EntitlementService::class)->setOverride($t['org'], 'pos_terminals', true, 1, 'test', $t['owner']);
        $this->actingAsApi($t['owner'], $t['org'])->api('POST', 'pos/terminals', ['warehouse_id' => $t['main']->id, 'code' => 'T2', 'name' => 'Second'])
            ->assertForbidden()->assertJsonPath('error.code', 'entitlement_limit_reached');

        $sale = $this->actingAsApi($t['cashier'], $t['org'])->api('POST', 'pos/sales', ['pos_shift_id' => $t['shift'], 'client_transaction_id' => (string) Str::ulid()])->json('data.id');
        [$otherCashier] = $this->organizationMember(['pos_cashier'], organization: $t['org']);
        $this->actingAsApi($otherCashier, $t['org'])->api('GET', "pos/sales/{$sale}")->assertForbidden()->assertJsonPath('error.code', 'pos_sale_not_yours');
        $this->api('POST', 'pos/sales', ['pos_shift_id' => $t['shift'], 'client_transaction_id' => (string) Str::ulid()])->assertForbidden()->assertJsonPath('error.code', 'shift_not_yours');

        [$ownerB, $orgB] = $this->organizationMember();
        $this->actingAsApi($ownerB, $orgB)->api('GET', "pos/sales/{$sale}")->assertNotFound();
    }

    public function test_pos_reports(): void
    {
        $t = $this->till();
        $sale = $this->cartAndCheckout($t, '2');
        $this->api('POST', "pos/sales/{$sale['id']}/payments", ['method' => 'cash', 'amount' => '6000'], $this->idem());

        $this->actingAsApi($t['cashier'], $t['org'])->api('GET', 'pos/reports/summary')->assertForbidden();
        $this->actingAsApi($t['supervisor'], $t['org'])->api('GET', 'pos/reports/summary')->assertOk()
            ->assertJsonPath('data.rows.sales_count', 1)->assertJsonPath('data.rows.net_sales', '6000.0000')
            ->assertJsonPath('data.rows.cost_of_goods', '1700.0000')->assertJsonPath('data.rows.gross_margin', '4300.0000');
        $this->api('GET', 'pos/reports/by-payment-method')->assertJsonPath('data.rows.0.method', 'cash');
        $this->api('GET', 'pos/reports/batches')->assertJsonCount(2, 'data.rows');
    }
}

<?php

namespace Tests\Feature;

use App\Domain\Catalogue\Models\OrganizationProduct;
use App\Domain\Parties\Models\Supplier;
use App\Domain\Procurement\Models\PurchaseOrder;
use App\Support\Money;
use Tests\Concerns\InteractsWithInventory;
use Tests\FeatureTestCase;

class ProcurementTest extends FeatureTestCase
{
    use InteractsWithInventory;

    private function idem(): array
    {
        return ['Idempotency-Key' => 'k-'.bin2hex(random_bytes(8))];
    }

    /** @return array{0: mixed, 1: mixed, 2: mixed, 3: mixed, 4: Supplier, 5: mixed} owner, org, product, main warehouse, supplier, approver */
    private function setUpBuying(): array
    {
        [$owner, $org, $product, $main] = $this->stockedOrganization();
        [$approver] = $this->organizationMember(['finance_manager'], organization: $org);
        $supplier = $this->inTenant($org, fn () => Supplier::query()->create([
            'code' => 'SUP1', 'name' => 'Acme Distribution', 'type' => 'distributor', 'currency_code' => 'NGN', 'payment_terms_days' => 30, 'lead_time_days' => 7,
        ]));

        return [$owner, $org, $product, $main, $supplier, $approver];
    }

    private function approvedOrder($owner, $org, $product, $main, $supplier, $approver, string $packs = '10', string $price = '2000'): array
    {
        $this->actingAsApi($owner, $org);
        $po = $this->api('POST', 'purchase-orders', ['supplier_id' => $supplier->id, 'warehouse_id' => $main->id, 'lines' => [
            ['product_id' => $product->id, 'package_level_code' => 'pack', 'quantity' => $packs, 'unit_price' => $price],
        ]])->assertCreated()->json('data');
        $this->api('POST', "purchase-orders/{$po['id']}/submit")->assertOk();
        $this->actingAsApi($approver, $org)->api('POST', "purchase-orders/{$po['id']}/approve")->assertOk();
        $this->actingAsApi($owner, $org);

        return $po;
    }

    public function test_requisition_to_purchase_order_to_receipt_to_matched_invoice(): void
    {
        [$owner, $org, $product, $main, $supplier, $approver] = $this->setUpBuying();
        [$pharmacist] = $this->organizationMember(['pharmacist'], organization: $org);

        // Supplier price list: 2,000 per pack of 20.
        $this->actingAsApi($owner, $org)->api('PUT', "suppliers/{$supplier->id}/price-list", ['items' => [
            ['product_id' => $product->id, 'package_level_code' => 'pack', 'unit_price' => '2000'],
        ]])->assertOk()->assertJsonCount(1, 'data');

        $req = $this->actingAsApi($pharmacist, $org)->api('POST', 'purchase-requisitions', [
            'warehouse_id' => $main->id, 'lines' => [['product_id' => $product->id, 'package_level_code' => 'pack', 'quantity' => '10', 'suggested_supplier_id' => $supplier->id]],
        ])->assertCreated()->assertJsonPath('data.number', 'PRQ-000001')->assertJsonPath('data.lines.0.base_quantity', '200.0000')->json('data.id');
        $this->api('POST', "purchase-requisitions/{$req}/submit")->assertOk()->assertJsonPath('data.status', 'submitted');
        $this->api('POST', "purchase-requisitions/{$req}/approve")->assertForbidden();

        $this->actingAsApi($approver, $org)->api('POST', "purchase-requisitions/{$req}/approve")->assertOk()->assertJsonPath('data.status', 'approved');

        $po = $this->actingAsApi($owner, $org)->api('POST', "purchase-requisitions/{$req}/convert")->assertCreated()->json('data.0');
        $this->assertSame('draft', $po['status']);
        $this->assertSame('2000.0000', $po['lines'][0]['unit_price'], 'price taken from the supplier price list');
        $this->assertSame('20000.0000', $po['total']);
        $this->assertSame(now()->addDays(7)->toDateString(), substr($po['expected_date'], 0, 10), 'expected date from supplier lead time');

        // No threshold configured: every PO needs approval, and not by its creator.
        $this->api('POST', "purchase-orders/{$po['id']}/submit")->assertOk()->assertJsonPath('data.status', 'pending_approval');
        $this->api('POST', "purchase-orders/{$po['id']}/approve")->assertForbidden()->assertJsonPath('error.code', 'self_approval_not_allowed');
        $this->actingAsApi($approver, $org)->api('POST', "purchase-orders/{$po['id']}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->actingAsApi($owner, $org)->api('POST', "purchase-orders/{$po['id']}/send")->assertOk()->assertJsonPath('data.status', 'sent');

        $lineId = $po['lines'][0]['id'];
        $grn = ['purchase_order_id' => $po['id'], 'supplier_delivery_note' => 'DN-77', 'lines' => [[
            'purchase_order_line_id' => $lineId, 'batch_number' => 'acme-001', 'expiry_date' => now()->addYears(2)->toDateString(), 'quantity' => '4',
        ]]];
        $this->api('POST', 'goods-receipts', $grn, $this->idem())->assertCreated()->assertJsonPath('data.number', 'GRN-000001')->assertJsonPath('data.lines.0.base_quantity', '80.0000');
        $this->assertSame('partially_received', PurchaseOrder::acrossTenants()->find($po['id'])->status);

        // Stock arrived at cost per base unit (2,000 / 20 = 100).
        $this->api('GET', "inventory/stock-levels?product_id={$product->id}")->assertJsonPath('data.0.sellable', '80.0000');
        $this->api('GET', 'inventory/valuation')->assertJsonPath('data.0.value', '8000.0000');

        $grn['lines'][0]['quantity'] = '6';
        $this->api('POST', 'goods-receipts', $grn, $this->idem())->assertCreated();
        $this->assertSame('received', PurchaseOrder::acrossTenants()->find($po['id'])->status);

        $invoice = $this->api('POST', 'supplier-invoices', [
            'supplier_id' => $supplier->id, 'purchase_order_id' => $po['id'], 'supplier_invoice_number' => 'ACME/INV/1001',
            'invoice_date' => today()->toDateString(), 'lines' => [['purchase_order_line_id' => $lineId, 'quantity' => '10', 'unit_price' => '2000']],
        ], $this->idem())->assertCreated()->assertJsonPath('data.match_status', 'matched')->assertJsonPath('data.total', '20000.0000')
            ->assertJsonPath('data.due_date', fn ($d) => str_starts_with($d, today()->addDays(30)->toDateString()))->json('data.id');

        $this->actingAsApi($approver, $org)->api('POST', "supplier-invoices/{$invoice}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertDatabaseHas('purchase_order_lines', ['id' => $lineId, 'invoiced_base_quantity' => '200.0000']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'goods_receipt.posted', 'organization_id' => $org->id]);
    }

    public function test_orders_below_threshold_are_auto_approved(): void
    {
        [$owner, $org, $product, $main, $supplier] = $this->setUpBuying();
        $this->actingAsApi($owner, $org)->api('PATCH', 'procurement/settings', ['approval_threshold' => '100000'])->assertOk();

        $po = $this->api('POST', 'purchase-orders', ['supplier_id' => $supplier->id, 'warehouse_id' => $main->id, 'lines' => [['product_id' => $product->id, 'quantity' => '5', 'unit_price' => '100']]])->json('data.id');
        $this->api('POST', "purchase-orders/{$po}/submit")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertDatabaseHas('audit_logs', ['action' => 'purchase_order.approved', 'auditable_id' => $po]);
    }

    public function test_line_money_is_computed_exactly(): void
    {
        [$owner, $org, $product, $main, $supplier] = $this->setUpBuying();

        $this->actingAsApi($owner, $org)->api('POST', 'purchase-orders', ['supplier_id' => $supplier->id, 'warehouse_id' => $main->id, 'lines' => [
            ['product_id' => $product->id, 'quantity' => '3', 'unit_price' => '1234.5678', 'tax_rate' => '7.5'],
        ]])->assertCreated()
            ->assertJsonPath('data.subtotal', '3703.7034')
            ->assertJsonPath('data.tax_total', '277.7778') // 277.777755 rounded half-up
            ->assertJsonPath('data.total', '3981.4812');

        $this->assertSame('0.0001', Money::round('0.00005'));
        $this->assertSame('-0.0001', Money::round('-0.00005'));
    }

    public function test_over_receipt_is_refused_unless_within_tolerance(): void
    {
        [$owner, $org, $product, $main, $supplier, $approver] = $this->setUpBuying();
        $po = $this->approvedOrder($owner, $org, $product, $main, $supplier, $approver, '10');
        $line = ['purchase_order_line_id' => $po['lines'][0]['id'], 'batch_number' => 'B1', 'expiry_date' => now()->addYear()->toDateString(), 'quantity' => '11'];

        $this->api('POST', 'goods-receipts', ['purchase_order_id' => $po['id'], 'lines' => [$line]], $this->idem())
            ->assertUnprocessable()->assertJsonPath('error.code', 'over_receipt');
        $this->api('GET', 'inventory/balances')->assertJsonCount(0, 'data');

        $this->api('PATCH', 'procurement/settings', ['over_receipt_tolerance_percent' => '10'])->assertOk();
        $this->api('POST', 'goods-receipts', ['purchase_order_id' => $po['id'], 'lines' => [$line]], $this->idem())->assertCreated();
    }

    public function test_receipts_cannot_be_posted_against_unapproved_orders(): void
    {
        [$owner, $org, $product, $main, $supplier] = $this->setUpBuying();
        $po = $this->actingAsApi($owner, $org)->api('POST', 'purchase-orders', ['supplier_id' => $supplier->id, 'warehouse_id' => $main->id, 'lines' => [['product_id' => $product->id, 'quantity' => '1', 'unit_price' => '1']]])->json('data');

        $this->api('POST', 'goods-receipts', ['purchase_order_id' => $po['id'], 'lines' => [['purchase_order_line_id' => $po['lines'][0]['id'], 'batch_number' => 'B', 'quantity' => '1']]], $this->idem())
            ->assertStatus(409)->assertJsonPath('error.code', 'purchase_order_not_receivable');
    }

    public function test_invoice_matching_flags_over_billing_and_price_variance(): void
    {
        [$owner, $org, $product, $main, $supplier, $approver] = $this->setUpBuying();
        $po = $this->approvedOrder($owner, $org, $product, $main, $supplier, $approver, '10', '2000');
        $lineId = $po['lines'][0]['id'];
        $this->api('POST', 'goods-receipts', ['purchase_order_id' => $po['id'], 'lines' => [['purchase_order_line_id' => $lineId, 'batch_number' => 'B1', 'expiry_date' => now()->addYear()->toDateString(), 'quantity' => '5']]], $this->idem())->assertCreated();

        $invoice = fn (string $number, string $qty, string $price) => $this->api('POST', 'supplier-invoices', [
            'supplier_id' => $supplier->id, 'purchase_order_id' => $po['id'], 'supplier_invoice_number' => $number,
            'invoice_date' => today()->toDateString(), 'lines' => [['purchase_order_line_id' => $lineId, 'quantity' => $qty, 'unit_price' => $price]],
        ], $this->idem());

        // Billing for 10 packs when only 5 arrived.
        $over = $invoice('INV-A', '10', '2000')->assertCreated()->assertJsonPath('data.match_status', 'unmatched')->json('data.id');
        $this->actingAsApi($approver, $org)->api('POST', "supplier-invoices/{$over}/approve")->assertStatus(409)->assertJsonPath('error.code', 'supplier_invoice_unmatched');
        $this->api('POST', "supplier-invoices/{$over}/cancel", ['reason' => 'Asked supplier for a corrected invoice'])->assertOk()->assertJsonPath('data.status', 'cancelled');

        $this->actingAsApi($owner, $org);
        $variance = $invoice('INV-B', '5', '2100')->assertCreated()->assertJsonPath('data.match_status', 'variance')
            ->assertJsonPath('data.match_details.0.issue', 'price_variance')->json('data.id');
        $this->actingAsApi($approver, $org)->api('POST', "supplier-invoices/{$variance}/approve")->assertOk();

        // The same supplier document cannot be entered twice.
        $this->actingAsApi($owner, $org);
        $invoice('INV-B', '1', '2000')->assertStatus(409)->assertJsonPath('error.code', 'supplier_invoice_duplicate');

        // Everything received is now invoiced: a further invoice is over-billing.
        $extra = $invoice('INV-C', '1', '2000')->assertJsonPath('data.match_status', 'unmatched')->json('data.id');
        $this->actingAsApi($approver, $org)->api('POST', "supplier-invoices/{$extra}/approve", ['override_reason' => 'Agreed with supplier: freight-adjusted unit'])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'supplier_invoice.approved', 'auditable_id' => $extra]);
    }

    public function test_order_rules(): void
    {
        [$owner, $org, $product, $main, $supplier, $approver] = $this->setUpBuying();
        $this->actingAsApi($owner, $org);

        $this->api('POST', 'purchase-orders', ['supplier_id' => $supplier->id, 'warehouse_id' => $main->id, 'lines' => [['product_id' => $product->id, 'quantity' => '1']]])
            ->assertUnprocessable()->assertJsonPath('error.code', 'price_required');

        $this->inTenant($org, fn () => $supplier->forceFill(['status' => 'on_hold'])->save());
        $this->api('POST', 'purchase-orders', ['supplier_id' => $supplier->id, 'warehouse_id' => $main->id, 'lines' => [['product_id' => $product->id, 'quantity' => '1', 'unit_price' => '1']]])
            ->assertStatus(409)->assertJsonPath('error.code', 'supplier_not_active');
        $this->inTenant($org, fn () => $supplier->forceFill(['status' => 'active'])->save());

        $po = $this->approvedOrder($owner, $org, $product, $main, $supplier, $approver, '2');
        $this->api('PATCH', "purchase-orders/{$po['id']}", ['notes' => 'late change'])->assertStatus(409)->assertJsonPath('error.code', 'purchase_order_locked');
        $this->api('POST', 'goods-receipts', ['purchase_order_id' => $po['id'], 'lines' => [['purchase_order_line_id' => $po['lines'][0]['id'], 'batch_number' => 'B1', 'expiry_date' => now()->addYear()->toDateString(), 'quantity' => '1']]], $this->idem())->assertCreated();
        $this->api('POST', "purchase-orders/{$po['id']}/cancel", ['reason' => 'x'])->assertStatus(409)->assertJsonPath('error.code', 'purchase_order_has_receipts');
        $this->api('POST', "purchase-orders/{$po['id']}/close", ['reason' => 'Supplier cannot deliver the rest'])->assertOk()->assertJsonPath('data.status', 'closed');
    }

    public function test_rejected_order_can_be_edited_and_resubmitted(): void
    {
        [$owner, $org, $product, $main, $supplier, $approver] = $this->setUpBuying();
        $po = $this->actingAsApi($owner, $org)->api('POST', 'purchase-orders', ['supplier_id' => $supplier->id, 'warehouse_id' => $main->id, 'lines' => [['product_id' => $product->id, 'quantity' => '100', 'unit_price' => '50']]])->json('data.id');
        $this->api('POST', "purchase-orders/{$po}/submit")->assertOk();

        $this->actingAsApi($approver, $org)->api('POST', "purchase-orders/{$po}/reject")->assertUnprocessable();
        $this->api('POST', "purchase-orders/{$po}/reject", ['reason' => 'Quantity too high'])->assertOk()->assertJsonPath('data.status', 'rejected');

        $this->actingAsApi($owner, $org)->api('PATCH', "purchase-orders/{$po}", ['lines' => [['product_id' => $product->id, 'quantity' => '40', 'unit_price' => '50']]])
            ->assertOk()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.total', '2000.0000');
        $this->api('POST', "purchase-orders/{$po}/submit")->assertOk()->assertJsonPath('data.status', 'pending_approval');
    }

    public function test_supplier_return_removes_stock_through_the_ledger(): void
    {
        [$owner, $org, $product, $main, $supplier] = $this->setUpBuying();
        $batch = $this->receive($org, $main, $product, 'BAD', '10', '+1 year', ['quality_status' => 'quarantined']);

        $this->actingAsApi($owner, $org)->api('POST', 'supplier-returns', [
            'supplier_id' => $supplier->id, 'warehouse_id' => $main->id, 'reason_code' => 'quality',
            'lines' => [['batch_id' => $batch->id, 'base_quantity' => '10', 'stock_state' => 'quarantined']],
        ], $this->idem())->assertCreated()->assertJsonPath('data.number', 'SRT-000001');

        $this->assertSame(['0.0000', '0.0000'], $this->balance($org, $main, $batch, 'quarantined'));
        $this->api('GET', 'inventory/movements?movement_type=supplier_return')->assertJsonCount(1, 'data');
        $this->api('POST', 'supplier-returns', ['supplier_id' => $supplier->id, 'warehouse_id' => $main->id, 'reason_code' => 'quality',
            'lines' => [['batch_id' => $batch->id, 'base_quantity' => '1', 'stock_state' => 'quarantined']]], $this->idem())->assertStatus(409);
    }

    public function test_suggestions_and_supplier_performance(): void
    {
        [$owner, $org, $product, $main, $supplier, $approver] = $this->setUpBuying();
        $this->inTenant($org, fn () => OrganizationProduct::query()->where('product_id', $product->id)->update(['reorder_level' => 50, 'reorder_quantity' => 400, 'preferred_supplier_id' => $supplier->id]));

        $this->actingAsApi($owner, $org)->api('GET', 'procurement/suggestions')
            ->assertOk()->assertJsonPath('data.0.product_id', $product->id)->assertJsonPath('data.0.suggested_base_quantity', '400.0000');

        $po = $this->approvedOrder($owner, $org, $product, $main, $supplier, $approver, '10');
        $this->api('POST', 'goods-receipts', ['purchase_order_id' => $po['id'], 'lines' => [['purchase_order_line_id' => $po['lines'][0]['id'], 'batch_number' => 'P1', 'expiry_date' => now()->addYear()->toDateString(), 'quantity' => '5']]], $this->idem());

        $this->api('GET', "suppliers/{$supplier->id}/performance")->assertOk()
            ->assertJsonPath('data.orders_placed', 1)
            ->assertJsonPath('data.on_time_rate', 1)
            ->assertJsonPath('data.fill_rate', 0.5);
        $this->api('GET', 'procurement/suggestions')->assertJsonCount(0, 'data');
    }

    public function test_procurement_is_tenant_isolated_and_role_gated(): void
    {
        [$owner, $org, $product, $main, $supplier, $approver] = $this->setUpBuying();
        [$ownerB, $orgB, , $mainB] = $this->stockedOrganization();
        $po = $this->approvedOrder($owner, $org, $product, $main, $supplier, $approver, '1');
        [$cashier] = $this->organizationMember(['pos_cashier'], organization: $org);

        $this->actingAsApi($ownerB, $orgB)->api('GET', "purchase-orders/{$po['id']}")->assertNotFound();
        $this->api('POST', "purchase-orders/{$po['id']}/cancel", ['reason' => 'x'])->assertNotFound();
        $this->api('POST', 'purchase-orders', ['supplier_id' => $supplier->id, 'warehouse_id' => $mainB->id, 'lines' => [['product_id' => $product->id, 'quantity' => '1', 'unit_price' => '1']]])
            ->assertUnprocessable()->assertJsonValidationErrors('supplier_id');
        $this->api('GET', 'purchase-orders')->assertJsonCount(0, 'data');

        $this->actingAsApi($cashier, $org)->api('GET', 'purchase-orders')->assertForbidden();
    }
}

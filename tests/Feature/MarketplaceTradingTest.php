<?php

namespace Tests\Feature;

use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Marketplace\Models\MarketplaceOrder;
use App\Domain\Parties\Models\Customer;
use App\Domain\Procurement\Models\PurchaseOrder;
use App\Domain\Sales\Models\SalesOrder;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithMarketplace;
use Tests\FeatureTestCase;

class MarketplaceTradingTest extends FeatureTestCase
{
    use InteractsWithMarketplace;

    private function order(array $b, array $s, string $listing, string $quantity = '10'): TestResponse
    {
        return $this->actingAsApi($b['buyer'], $b['org'])->api('POST', 'marketplace/orders', [
            'supplier_organization_id' => $s['org']->id, 'delivery_warehouse_id' => $b['warehouse']->id,
            'lines' => [['listing_id' => $listing, 'quantity' => $quantity]],
        ]);
    }

    public function test_an_order_becomes_the_buyers_purchase_order_and_the_suppliers_confirmed_sales_order(): void
    {
        $s = $this->listedSupplier();
        $listing = $this->listing($s, $s['amox']); // 1,200 per pack of 20, minimum 10 packs
        $b = $this->tradingBuyer($s);

        $this->order($b, $s, $listing, '5')->assertUnprocessable()->assertJsonPath('error.code', 'below_minimum_order_quantity');
        $order = $this->order($b, $s, $listing)->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.subtotal', '12000.0000')->json('data');

        $submitted = $this->api('POST', "marketplace/orders/{$order['id']}/submit")->assertOk()->assertJsonPath('data.status', 'submitted')->json('data');
        $this->assertSame('sent', $submitted['erp_document']['status'], 'the buyer\'s purchase order went out (below the approval threshold)');
        $po = $this->inTenant($b['org'], fn () => PurchaseOrder::query()->with('lines')->sole());
        $this->assertSame(['12000.0000', '1200.0000'], [(string) $po->total, (string) $po->lines->sole()->unit_price]);

        // The buyer cannot accept its own order; the supplier can.
        $this->api('POST', "marketplace/orders/{$order['id']}/accept")->assertForbidden();
        $accepted = $this->actingAsApi($s['owner'], $s['org'])->api('POST', "marketplace/orders/{$order['id']}/accept")->assertOk()->json('data');
        $this->assertSame(['accepted', 'supplier', 'confirmed'], [$accepted['status'], $accepted['your_side'], $accepted['erp_document']['status']]);

        $so = $this->inTenant($s['org'], fn () => SalesOrder::query()->with('lines')->sole());
        $this->assertSame('1200.0000', (string) $so->lines->sole()->unit_price, 'the agreed marketplace price is honoured');
        $this->assertSame($b['org']->id, $this->inTenant($s['org'], fn () => Customer::query()->find($so->customer_id)->linked_organization_id), 'sold to the linked customer record of the buyer');
        $this->assertSame('200.0000', (string) $this->inTenant($s['org'], fn () => StockReservation::query()->where('status', 'active')->sum('quantity')), '10 packs of 20 reserved FEFO');

        // Each side sees only its own ERP document.
        $buyerView = $this->actingAsApi($b['buyer'], $b['org'])->api('GET', "marketplace/orders/{$order['id']}")->json('data');
        $this->assertSame($po->number, $buyerView['erp_document']['number']);
        $this->assertSame($s['org']->name, $buyerView['counterpart']['name']);
        $this->assertStringContainsString('not an approval by a regulator', $buyerView['counterpart']['verification']['label']);
    }

    public function test_the_buyers_procurement_approval_rules_gate_the_order(): void
    {
        $s = $this->listedSupplier();
        $listing = $this->listing($s, $s['amox']);
        $b = $this->tradingBuyer($s, threshold: '10000');
        $order = $this->order($b, $s, $listing)->json('data.id');

        $this->api('POST', "marketplace/orders/{$order}/submit")->assertOk()->assertJsonPath('data.status', 'pending_approval');
        $this->actingAsApi($s['owner'], $s['org'])->api('POST', "marketplace/orders/{$order}/accept")->assertStatus(409);

        // The finance manager disapproves with a reason; the order returns to draft.
        $this->actingAsApi($b['approver'], $b['org'])->api('POST', "marketplace/orders/{$order}/disapprove", ['reason' => 'Split across two months'])
            ->assertOk()->assertJsonPath('data.status', 'draft');
        $this->actingAsApi($b['buyer'], $b['org'])->api('POST', "marketplace/orders/{$order}/submit")->assertOk()->assertJsonPath('data.status', 'pending_approval');
        $this->actingAsApi($b['approver'], $b['org'])->api('POST', "marketplace/orders/{$order}/approve")->assertOk()->assertJsonPath('data.status', 'submitted');
        $this->assertSame(1, $this->inTenant($b['org'], fn () => PurchaseOrder::query()->count()), 'the same purchase order was reused');
    }

    public function test_rfq_quotes_comparison_and_award(): void
    {
        $s1 = $this->listedSupplier();
        $s2 = $this->listedSupplier();
        $outsider = $this->listedSupplier();
        $b = $this->tradingBuyer($s1);
        $rel2 = $this->actingAsApi($b['buyer'], $b['org'])->api('POST', 'marketplace/relationships', ['supplier_organization_id' => $s2['org']->id])->json('data.id');
        $this->actingAsApi($s2['owner'], $s2['org'])->api('POST', "marketplace/relationships/{$rel2}/approve")->assertOk();

        $rfq = $this->actingAsApi($b['buyer'], $b['org'])->api('POST', 'marketplace/rfqs', [
            'title' => 'Amoxicillin for Q1', 'response_deadline' => now()->addDays(3)->toIso8601String(), 'delivery_warehouse_id' => $b['warehouse']->id,
            'lines' => [['product_id' => $s1['amox']->id, 'package_level_code' => 'pack', 'quantity' => '20']],
            'supplier_organization_ids' => [$s1['org']->id, $s2['org']->id],
        ])->assertCreated()->assertJsonPath('data.status', 'draft')->json('data');
        $lineId = $rfq['lines'][0]['id'];

        // Not visible to suppliers until opened.
        $this->actingAsApi($s1['owner'], $s1['org'])->api('GET', "marketplace/rfqs/{$rfq['id']}")->assertNotFound();
        $this->actingAsApi($b['buyer'], $b['org'])->api('POST', "marketplace/rfqs/{$rfq['id']}/open")->assertOk()->assertJsonPath('data.status', 'open');

        $seen = $this->actingAsApi($s1['owner'], $s1['org'])->api('GET', "marketplace/rfqs/{$rfq['id']}")->assertOk()->json('data');
        $this->assertCount(1, $seen['invitations'], 'a supplier does not see who else was invited');
        $this->api('POST', "marketplace/rfqs/{$rfq['id']}/quotes", ['valid_until' => today()->addWeek()->toDateString(), 'lines' => [['rfq_line_id' => $lineId, 'quantity' => '20', 'unit_price' => '1100', 'lead_time_days' => 3]]])->assertCreated();
        $this->actingAsApi($s2['owner'], $s2['org'])->api('POST', "marketplace/rfqs/{$rfq['id']}/quotes", ['valid_until' => today()->addWeek()->toDateString(), 'lines' => [['rfq_line_id' => $lineId, 'quantity' => '20', 'unit_price' => '1000']]])->assertCreated();
        $best = $this->api('GET', 'marketplace/quotes', ['rfq_id' => $rfq['id']])->json('data.0.id');
        $this->actingAsApi($outsider['owner'], $outsider['org'])->api('POST', "marketplace/rfqs/{$rfq['id']}/quotes", ['valid_until' => today()->addWeek()->toDateString(), 'lines' => [['rfq_line_id' => $lineId, 'quantity' => '20', 'unit_price' => '900']]])
            ->assertNotFound();

        $comparison = $this->actingAsApi($b['buyer'], $b['org'])->api('GET', "marketplace/rfqs/{$rfq['id']}/comparison")->assertOk()->json('data');
        $offers = $comparison['lines'][0]['offers'];
        $this->assertSame(['1000.0000', '1100.0000'], array_column($offers, 'unit_price'), 'cheapest first');
        $this->assertSame('1000.0000', $comparison['lines'][0]['best_unit_price']);

        $order = $this->api('POST', "marketplace/quotes/{$best}/accept")->assertCreated()->json('data');
        $this->assertSame(['draft', '20000.0000', $s2['org']->name], [$order['status'], $order['subtotal'], $order['counterpart']['name']]);
        $this->api('GET', "marketplace/rfqs/{$rfq['id']}")->assertJsonPath('data.status', 'awarded');
        $this->api('POST', "marketplace/quotes/{$best}/accept")->assertStatus(409);
    }

    public function test_restrictions_rejection_cancellation_and_stock_checks_cross_both_sides(): void
    {
        $s = $this->listedSupplier();
        $rx = $this->listing($s, $s['rx']);
        $amox = $this->listing($s, $s['amox']);
        $unverified = $this->tradingBuyer($s, verified: false);
        $this->order($unverified, $s, $rx)->assertForbidden()->assertJsonPath('error.code', 'buyer_not_verified');

        $b = $this->tradingBuyer($s);
        // Rejection by the supplier cancels the buyer's purchase order.
        $one = $this->order($b, $s, $amox)->json('data.id');
        $this->api('POST', "marketplace/orders/{$one}/submit")->assertOk();
        $this->actingAsApi($s['owner'], $s['org'])->api('POST', "marketplace/orders/{$one}/reject", ['reason' => 'Batch reserved for a tender'])->assertOk()->assertJsonPath('data.status', 'rejected');
        $po = $this->inTenant($b['org'], fn () => PurchaseOrder::query()->find(MarketplaceOrder::query()->find($one)->buyer_purchase_order_id));
        $this->assertSame('cancelled', $po->status);
        $this->assertStringContainsString('[via marketplace]', $po->status_reason);

        // More than the supplier holds: acceptance fails and nothing is created.
        $big = $this->order($b, $s, $amox, '500')->json('data.id');
        $this->actingAsApi($b['buyer'], $b['org'])->api('POST', "marketplace/orders/{$big}/submit")->assertOk()->assertJsonPath('data.status', 'pending_approval'); // 600,000 is over the threshold
        $this->actingAsApi($b['approver'], $b['org'])->api('POST', "marketplace/orders/{$big}/approve")->assertOk()->assertJsonPath('data.status', 'submitted');
        $this->actingAsApi($s['owner'], $s['org'])->api('POST', "marketplace/orders/{$big}/accept")->assertStatus(409)->assertJsonPath('error.code', 'insufficient_stock');
        $this->assertSame('submitted', MarketplaceOrder::query()->find($big)->status);
        $this->assertSame(0, $this->inTenant($s['org'], fn () => SalesOrder::query()->count()));

        // Accepted, then cancelled by the supplier before shipping: reservations released, buyer PO cancelled.
        $two = $this->order($b, $s, $amox)->json('data.id');
        $this->actingAsApi($b['buyer'], $b['org'])->api('POST', "marketplace/orders/{$two}/submit")->assertOk();
        $this->actingAsApi($s['owner'], $s['org'])->api('POST', "marketplace/orders/{$two}/accept")->assertOk();
        $this->actingAsApi($b['buyer'], $b['org'])->api('POST', "marketplace/orders/{$two}/cancel", ['reason' => 'Changed my mind'])->assertStatus(409);
        $this->actingAsApi($s['owner'], $s['org'])->api('POST', "marketplace/orders/{$two}/cancel", ['reason' => 'Cold-chain truck unavailable'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame(0, (int) $this->inTenant($s['org'], fn () => StockReservation::query()->where('status', 'active')->count()));
        $this->assertSame('cancelled', $this->inTenant($b['org'], fn () => PurchaseOrder::query()->find(MarketplaceOrder::query()->find($two)->buyer_purchase_order_id)->status));

        // Strangers see nothing.
        [$other, $otherOrg] = $this->marketplaceOrg('pharmacy');
        $this->actingAsApi($other, $otherOrg)->api('GET', "marketplace/orders/{$two}")->assertNotFound();
    }
}

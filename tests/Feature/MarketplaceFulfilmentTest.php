<?php

namespace Tests\Feature;

use App\Domain\Inventory\Models\Batch;
use App\Domain\Marketplace\Models\MarketplaceFee;
use App\Domain\Marketplace\Models\MarketplaceInvoice;
use App\Domain\Marketplace\Models\TradingRelationship;
use App\Domain\Procurement\Models\GoodsReceipt;
use App\Domain\Procurement\Models\SupplierInvoice;
use App\Domain\Sales\Models\CreditNote;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithMarketplace;
use Tests\FeatureTestCase;

class MarketplaceFulfilmentTest extends FeatureTestCase
{
    use InteractsWithMarketplace;

    private function idem(): array
    {
        return ['Idempotency-Key' => 'mk-'.Str::lower(Str::random(16))];
    }

    /** An accepted order for 10 packs of 20 Amoxicillin capsules at 1,200 a pack. */
    private function acceptedOrder(): array
    {
        $s = $this->listedSupplier();
        $listing = $this->listing($s, $s['amox']);
        $b = $this->tradingBuyer($s);
        $order = $this->actingAsApi($b['buyer'], $b['org'])->api('POST', 'marketplace/orders', [
            'supplier_organization_id' => $s['org']->id, 'delivery_warehouse_id' => $b['warehouse']->id, 'lines' => [['listing_id' => $listing, 'quantity' => '10']],
        ])->json('data');
        $this->api('POST', "marketplace/orders/{$order['id']}/submit")->assertOk();
        $this->actingAsApi($s['owner'], $s['org'])->api('POST', "marketplace/orders/{$order['id']}/accept")->assertOk();

        return ['s' => $s, 'b' => $b, 'order' => $order['id'], 'line' => $order['lines'][0]['id']];
    }

    private function ship(array $t, string $packs)
    {
        return $this->actingAsApi($t['s']['owner'], $t['s']['org'])->api('POST', "marketplace/orders/{$t['order']}/shipments",
            ['carrier' => 'Demo Haulage', 'tracking_reference' => 'TRK-'.Str::random(6), 'lines' => [['order_line_id' => $t['line'], 'quantity' => $packs]]], $this->idem());
    }

    public function test_ship_receive_invoice_record_and_pay_with_batch_evidence_and_a_fee(): void
    {
        $t = $this->acceptedOrder();

        $first = $this->ship($t, '6')->assertCreated()->json('data');
        $this->assertSame([['batch_number' => 'MP-AMX-1', 'expiry_date' => now()->addYears(2)->toDateString(), 'base_quantity' => '120.0000']], $first['lines'][0]['batches'],
            'the batch that physically left travels with the shipment');
        $this->ship($t, '5')->assertUnprocessable()->assertJsonPath('error.code', 'ship_quantity_invalid');

        // The buyer receives into its purchase order with the supplier's batch.
        $this->actingAsApi($t['b']['buyer'], $t['b']['org'])->api('POST', "marketplace/shipments/{$first['id']}/receive", [], $this->idem())->assertOk()->assertJsonPath('data.status', 'received');
        $grn = $this->inTenant($t['b']['org'], fn () => GoodsReceipt::query()->sole());
        $batch = $this->inTenant($t['b']['org'], fn () => Batch::query()->where('batch_number', 'MP-AMX-1')->sole());
        $this->assertSame(now()->addYears(2)->toDateString(), $batch->expiry_date->toDateString());
        $this->assertSame(['120.0000', '0.0000'], $this->balance($t['b']['org'], $t['b']['warehouse'], $batch));
        $this->assertSame('MSH-000001', $grn->supplier_delivery_note);

        $second = $this->ship($t, '4')->json('data');
        $this->actingAsApi($t['b']['buyer'], $t['b']['org'])->api('POST', "marketplace/shipments/{$second['id']}/receive", [], $this->idem())->assertOk();
        $this->api('GET', "marketplace/orders/{$t['order']}")->assertJsonPath('data.status', 'received');

        // The supplier invoices; the fee accrues on the net value.
        $invoice = $this->actingAsApi($t['s']['owner'], $t['s']['org'])->api('POST', "marketplace/orders/{$t['order']}/invoices", [], $this->idem())->assertCreated()->json('data');
        $this->assertSame(['12000.0000', '12000.0000'], [$invoice['net_amount'], $invoice['total']]);
        $fee = MarketplaceFee::query()->sole();
        $this->assertSame(['12000.0000', '1.5000', '180.0000', 'accrued'], [(string) $fee->basis_amount, (string) $fee->rate_percent, (string) $fee->amount, $fee->status]);
        $this->api('POST', "marketplace/orders/{$t['order']}/invoices", [], $this->idem())->assertStatus(409);
        $this->api('GET', 'marketplace/fees')->assertOk()->assertJsonPath('meta.accrued_total', '180.0000');

        // The buyer records it in its own books; three-way match against PO and receipts.
        $this->actingAsApi($t['b']['buyer'], $t['b']['org'])->api('POST', "marketplace/invoices/{$invoice['id']}/record")->assertOk();
        $recorded = $this->inTenant($t['b']['org'], fn () => SupplierInvoice::query()->sole());
        $this->assertSame([$invoice['number'], 'matched', '12000.0000'], [$recorded->supplier_invoice_number, $recorded->match_status, (string) $recorded->total]);
        $this->api('POST', "marketplace/invoices/{$invoice['id']}/record")->assertStatus(409);

        // Payment status comes from the supplier's receivables.
        $this->api('GET', "marketplace/orders/{$t['order']}/invoices")->assertJsonPath('data.0.payment_status.status', 'unpaid')->assertJsonPath('data.0.payment_status.open_balance', '12000.0000');
        $rel = TradingRelationship::query()->sole();
        $this->actingAsApi($t['s']['owner'], $t['s']['org'])->api('POST', 'customer-payments', ['customer_id' => $rel->supplier_customer_id, 'amount' => '12000', 'method' => 'bank_transfer',
            'payment_date' => today()->toDateString(), 'allocations' => [['sales_invoice_id' => MarketplaceInvoice::query()->sole()->supplier_sales_invoice_id, 'amount' => '12000']]], $this->idem())->assertCreated();
        $this->actingAsApi($t['b']['buyer'], $t['b']['org'])->api('GET', "marketplace/orders/{$t['order']}/invoices")
            ->assertJsonPath('data.0.payment_status.status', 'paid')->assertJsonPath('data.0.payment_status.open_balance', '0.0000');

        $events = collect($this->api('GET', "marketplace/orders/{$t['order']}/history")->assertOk()->json('data.events'))->pluck('event')->all();
        $this->assertSame(['created', 'submitted_to_supplier', 'accepted', 'shipped', 'received', 'shipped', 'received', 'invoiced'], $events);

        // Platform: fee ledger and waiver, which only platform staff can do.
        $this->api('GET', 'admin/marketplace/fees')->assertForbidden();
        $ops = $this->platformUser('platform_operations_admin');
        $this->actingAsApi($ops)->api('GET', 'admin/marketplace/fees')->assertOk()->assertJsonPath('meta.by_supplier.0.amount', '180.0000');
        $this->api('POST', "admin/marketplace/fees/{$fee->id}/waive", ['reason' => 'Launch promotion'])->assertOk()->assertJsonPath('data.status', 'waived');
        $this->api('POST', "admin/marketplace/fees/{$fee->id}/waive", ['reason' => 'again'])->assertStatus(409);
        $this->assertSame('180.0000', (string) $fee->fresh()->amount, 'the amount is never edited, only waived');
    }

    public function test_short_delivery_dispute_credit_note_return_and_escalation(): void
    {
        $t = $this->acceptedOrder();
        $shipment = $this->ship($t, '10')->json('data');
        $shipLine = $shipment['lines'][0]['id'];

        // Only 8 packs arrive.
        $this->actingAsApi($t['b']['buyer'], $t['b']['org'])->api('POST', "marketplace/shipments/{$shipment['id']}/receive",
            ['lines' => [['shipment_line_id' => $shipLine, 'quantity' => '8']]], $this->idem())->assertOk()->assertJsonPath('data.status', 'received_short');
        $short = $this->api('POST', "marketplace/orders/{$t['order']}/disputes", ['type' => 'short_delivery', 'requested_resolution' => 'credit',
            'description' => 'Two packs missing from the carton.', 'lines' => [['marketplace_order_line_id' => $t['line'], 'quantity' => '2']]])->assertCreated()->json('data');

        $this->api('POST', "marketplace/disputes/{$short['id']}/resolve", ['resolution' => 'rejected', 'notes' => 'x'])->assertForbidden()->assertJsonPath('error.code', 'dispute_wrong_side');
        $this->actingAsApi($t['s']['owner'], $t['s']['org']);
        $this->api('POST', "marketplace/disputes/{$short['id']}/messages", ['body' => 'Checking the loading record.'])->assertOk();
        $this->api('POST', "marketplace/disputes/{$short['id']}/resolve", ['resolution' => 'credit_note', 'notes' => 'Credit for 2 packs',
            'credit_lines' => [['marketplace_order_line_id' => $t['line'], 'quantity' => '2']]])->assertStatus(409)->assertJsonPath('error.code', 'order_not_invoiced');
        $this->api('POST', "marketplace/orders/{$t['order']}/invoices", [], $this->idem())->assertCreated();
        $this->api('POST', "marketplace/disputes/{$short['id']}/resolve", ['resolution' => 'credit_note', 'notes' => 'Credit for 2 packs',
            'credit_lines' => [['marketplace_order_line_id' => $t['line'], 'quantity' => '2']]])->assertOk()->assertJsonPath('data.status', 'resolved');
        $this->assertSame('2400.0000', (string) $this->inTenant($t['s']['org'], fn () => CreditNote::query()->sole()->total));
        $this->actingAsApi($t['b']['buyer'], $t['b']['org'])->api('GET', "marketplace/orders/{$t['order']}/invoices")->assertJsonPath('data.0.payment_status.open_balance', '9600.0000');

        // A quality complaint: the buyer sends one pack back from the received batch; the supplier credits and quarantines it.
        $quality = $this->api('POST', "marketplace/orders/{$t['order']}/disputes", ['type' => 'quality', 'requested_resolution' => 'return_and_credit',
            'description' => 'Crushed blisters in one pack.', 'lines' => [['marketplace_order_line_id' => $t['line'], 'quantity' => '1', 'batch_number' => 'MP-AMX-1']]])->json('data');
        $this->api('POST', "marketplace/disputes/{$quality['id']}/return", ['lines' => [['marketplace_order_line_id' => $t['line'], 'quantity' => '1', 'batch_number' => 'MP-AMX-1']]])->assertOk();
        $buyerBatch = $this->inTenant($t['b']['org'], fn () => Batch::query()->where('batch_number', 'MP-AMX-1')->sole());
        $this->assertSame(['140.0000', '0.0000'], $this->balance($t['b']['org'], $t['b']['warehouse'], $buyerBatch), '160 received − 20 returned');

        $this->actingAsApi($t['s']['owner'], $t['s']['org'])->api('POST', "marketplace/disputes/{$quality['id']}/resolve", ['resolution' => 'credit_note', 'notes' => 'Returned pack credited',
            'goods_returned' => true, 'credit_lines' => [['marketplace_order_line_id' => $t['line'], 'quantity' => '1', 'batch_number' => 'MP-AMX-1']]])->assertOk();
        $this->assertSame(['20.0000', '0.0000'], $this->balance($t['s']['org'], $t['s']['main'], $this->inTenant($t['s']['org'], fn () => Batch::query()->where('batch_number', 'MP-AMX-1')->sole()), 'quarantined'),
            'returned goods come back quarantined');

        // The buyer escalates; the platform sees it and comments, without posting to either side's books.
        $this->actingAsApi($t['b']['buyer'], $t['b']['org'])->api('POST', "marketplace/disputes/{$quality['id']}/escalate", ['reason' => 'Want a replacement, not a credit'])->assertOk()->assertJsonPath('data.status', 'escalated');
        $ops = $this->platformUser('platform_operations_admin');
        $this->actingAsApi($ops)->api('GET', 'admin/marketplace/disputes')->assertOk()->assertJsonPath('data.0.id', $quality['id']);
        $this->api('POST', "admin/marketplace/disputes/{$quality['id']}/notes", ['body' => 'Please agree a replacement within 5 days.'])->assertOk()->assertJsonPath('data.messages.4.author_side', 'platform');

        [$stranger, $strangerOrg] = $this->marketplaceOrg('pharmacy');
        $this->actingAsApi($stranger, $strangerOrg)->api('GET', "marketplace/disputes/{$quality['id']}")->assertNotFound();
    }
}

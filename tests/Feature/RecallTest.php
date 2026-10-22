<?php

namespace Tests\Feature;

use App\Domain\Catalogue\Models\Manufacturer;
use App\Domain\Inventory\Models\Batch;
use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Parties\Models\Customer;
use App\Domain\Traceability\Models\TraceEvent;
use App\Notifications\RecallNoticeNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithMarketplace;
use Tests\FeatureTestCase;

/**
 * Phase 7b acceptance: authorized users identify the participating locations holding affected
 * batches; recalled stock is blocked; recall notices are acknowledged; recovery and exposure are
 * reported with an explicit coverage statement; regulatory incidents carry their deadlines.
 */
class RecallTest extends FeatureTestCase
{
    use InteractsWithMarketplace;

    public function test_a_recall_quarantines_the_lot_everywhere_and_notifies_every_holder(): void
    {
        Notification::fake();
        $t = $this->recallScenario();
        $officer = $this->platformUser('compliance_officer');
        $second = $this->platformUser('compliance_officer');

        $recall = $this->actingAsApi($officer)->api('POST', 'admin/recalls', $this->recallPayload($t))->assertCreated()->json('data');
        $this->assertSame(['draft', ['MP-AMX-1']], [$recall['status'], $recall['batch_numbers']]);
        $this->api('POST', "admin/recalls/{$recall['id']}/activate")->assertStatus(409)->assertJsonPath('error.code', 'approval_segregation');
        $this->actingAsApi($second)->api('POST', "admin/recalls/{$recall['id']}/activate")->assertOk()->assertJsonPath('data.status', 'active');

        // Every holder: batch recalled, available stock quarantined, reservations released, a notice and an e-mail.
        $supplierBatch = $this->inTenant($t['s']['org'], fn () => Batch::query()->where('batch_number', 'MP-AMX-1')->sole());
        $buyerBatch = $this->inTenant($t['b']['org'], fn () => Batch::query()->where('batch_number', 'MP-AMX-1')->sole());
        $this->assertSame(['recalled', 'recalled'], [$supplierBatch->recall_status, $buyerBatch->recall_status]);
        $this->assertSame(['0.0000', '0.0000'], $this->balance($t['s']['org'], $t['s']['main'], $supplierBatch));
        $this->assertSame(['1840.0000', '0.0000'], $this->balance($t['s']['org'], $t['s']['main'], $supplierBatch, 'quarantined'));
        $this->assertSame(['120.0000', '0.0000'], $this->balance($t['b']['org'], $t['b']['warehouse'], $buyerBatch, 'quarantined'));
        $this->assertSame(0, StockReservation::acrossTenants()->where('batch_id', $supplierBatch->id)->where('status', 'active')->count());
        Notification::assertSentTo($t['b']['owner'], RecallNoticeNotification::class, fn ($n) => $n->recallNumber === $recall['number'] && $n->heldAtNotice === '120.0000');
        Notification::assertSentTo($t['s']['owner'], RecallNoticeNotification::class);
        $this->assertSame(2, TraceEvent::query()->where('event_type', 'recall')->count(), 'each holder\'s chain records the recall');

        // Recalled stock cannot be sold or shipped.
        $this->actingAsApi($t['s']['owner'], $t['s']['org'])->api('POST', 'sales-orders', ['customer_id' => $t['offPlatform']->id, 'warehouse_id' => $t['s']['main']->id,
            'lines' => [['product_id' => $t['s']['amox']->id, 'package_level_code' => 'pack', 'quantity' => '1', 'unit_price' => '1200']]], $this->idem())->assertCreated()
            ->tap(fn ($r) => $this->api('POST', 'sales-orders/'.$r->json('data.id').'/confirm')->assertStatus(409));
        $token = $this->api('POST', 'trace/identifiers', ['kind' => 'batch', 'product_id' => $t['s']['amox']->id, 'batch_number' => 'MP-AMX-1'])->json('data.token');
        $this->assertSame('recalled', $this->getJson('/api/v1/trace/scan?code='.$token)->json('data.result'));

        // The pharmacy acknowledges, writes the stock off and completes; nothing is unaccounted.
        $this->actingAsApi($t['b']['owner'], $t['b']['org']);
        $notice = collect($this->api('GET', 'recall-notices?status=pending')->assertOk()->json('data'))->sole();
        $this->assertSame(['120.0000', '120.0000'], [$notice['held_at_notice'], $notice['quarantined_at_notice']]);
        $this->api('POST', "recall-notices/{$notice['id']}/complete")->assertStatus(409);
        $this->api('POST', "recall-notices/{$notice['id']}/acknowledge", ['notes' => 'Quarantined in the dispensary store'])->assertOk();
        $this->api('POST', 'stock-adjustments', ['warehouse_id' => $t['b']['warehouse']->id, 'reason_code' => 'recall', 'notes' => 'Recall: destroyed under supervision',
            'lines' => [['batch_id' => $buyerBatch->id, 'stock_state' => 'quarantined', 'quantity_delta' => '-120']]], $this->idem())->assertCreated();
        $done = $this->api('POST', "recall-notices/{$notice['id']}/complete", ['notes' => 'Destroyed'])->assertOk()->json('data');
        $this->assertSame(['completed', '120.0000', '0.0000', '0.0000'], [$done['status'], $done['recovery']['written_off'], $done['recovery']['still_held'], $done['recovery']['unaccounted']]);

        // Exposure: holders, what went outside the platform before the recall, and the coverage statement.
        $exposure = $this->actingAsApi($officer)->api('GET', "admin/recalls/{$recall['id']}/exposure")->assertOk()->json('data');
        $rows = collect($exposure['organizations'])->keyBy('organization_id');
        $this->assertSame(['40.0000', ['Off-platform Clinic']], [$rows[$t['s']['org']->id]['shipped_outside_platform'], $rows[$t['s']['org']->id]['outside_platform_parties']]);
        $this->assertSame('120.0000', $rows[$t['s']['org']->id]['shipped_to_platform_organizations']);
        $this->assertSame([2, 1], [$exposure['totals']['organizations_notified'], $exposure['totals']['acknowledged']]);
        $this->assertStringContainsString('Coverage is therefore not complete', $exposure['coverage']['statement']);
        $this->assertSame(1, $exposure['coverage']['outside_platform_parties']);

        // The distributor sees its own customers to contact; the pharmacy cannot read the exposure report.
        $this->actingAsApi($t['s']['owner'], $t['s']['org']);
        $supplierNotice = collect($this->api('GET', 'recall-notices')->json('data'))->sole();
        $customers = $this->api('GET', "recall-notices/{$supplierNotice['id']}")->json('data.your_customers');
        $this->assertEqualsCanonicalizing([['Off-platform Clinic', false], [$t['b']['org']->name, true]],
            collect($customers)->map(fn ($c) => [$c['customer'], $c['on_platform']])->all());
        $this->actingAsApi($t['b']['owner'], $t['b']['org'])->api('GET', "recalls/{$recall['id']}/exposure")->assertForbidden();

        // Closing needs every notice acknowledged, or an override with a reason.
        $this->actingAsApi($officer)->api('POST', "admin/recalls/{$recall['id']}/close", ['notes' => 'Done'])->assertStatus(409)->assertJsonPath('error.details.pending', 1);
        $this->api('POST', "admin/recalls/{$recall['id']}/close", ['notes' => 'Done', 'override_reason' => 'Distributor confirmed by phone; acknowledgement to follow'])
            ->assertOk()->assertJsonPath('data.status', 'closed');
    }

    public function test_brand_owners_recall_their_own_products_with_a_second_approver(): void
    {
        Notification::fake();
        $t = $this->recallScenario();
        [$mfrOwner, $mfr] = $this->marketplaceOrg('manufacturer');
        [$mfrAdmin] = $this->organizationMember(['org_admin'], organization: $mfr);
        $manufacturer = Manufacturer::query()->create(['name' => 'Demo Brand Owner', 'country_code' => 'NG', 'organization_id' => $mfr->id]);

        $this->actingAsApi($mfrOwner, $mfr)->api('POST', 'recalls', $this->recallPayload($t))->assertStatus(403)->assertJsonPath('error.code', 'recall_not_brand_owner');
        $t['s']['amox']->forceFill(['manufacturer_id' => $manufacturer->id])->saveQuietly();
        $recall = $this->api('POST', 'recalls', $this->recallPayload($t))->assertCreated()->json('data');
        $this->api('POST', "recalls/{$recall['id']}/activate")->assertStatus(409);
        $this->actingAsApi($mfrAdmin, $mfr)->api('POST', "recalls/{$recall['id']}/activate")->assertOk();
        $this->api('GET', "recalls/{$recall['id']}/exposure")->assertOk()->assertJsonPath('data.totals.organizations_notified', 2);

        // A holder sees the recall through its notice but cannot manage it; others do not see it at all.
        $this->actingAsApi($t['b']['owner'], $t['b']['org'])->api('GET', "recalls/{$recall['id']}")->assertOk()->assertJsonPath('data.your_notice.held_at_notice', '120.0000');
        $this->api('POST', "recalls/{$recall['id']}/close", ['notes' => 'x'])->assertStatus(403);
        [$other, $otherOrg] = $this->marketplaceOrg('pharmacy');
        $this->actingAsApi($other, $otherOrg)->api('GET', "recalls/{$recall['id']}")->assertNotFound();

        // Cancelled in error: the batches are no longer recalled; quarantined stock waits for a person.
        $this->actingAsApi($mfrOwner, $mfr)->api('POST', "recalls/{$recall['id']}/cancel", ['reason' => 'Wrong batch number entered'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $batch = $this->inTenant($t['b']['org'], fn () => Batch::query()->where('batch_number', 'MP-AMX-1')->sole());
        $this->assertSame('none', $batch->recall_status);
        $this->assertSame(['120.0000', '0.0000'], $this->balance($t['b']['org'], $t['b']['warehouse'], $batch, 'quarantined'));
        Notification::assertSentTo($t['b']['owner'], RecallNoticeNotification::class, fn ($n) => $n->cancelled);
    }

    public function test_regulatory_incidents_carry_the_reporting_deadline(): void
    {
        [$owner, $org] = $this->marketplaceOrg('pharmacy');
        $this->actingAsApi($owner, $org);
        $stolen = $this->api('POST', 'regulatory-incidents', ['type' => 'stolen', 'description' => 'Two cartons taken from the delivery van', 'quantity' => '400',
            'detected_at' => now()->subHours(2)->toIso8601String()])->assertCreated()->json('data');
        $this->assertSame(now()->subHours(2)->addHours(24)->toDateTimeString(), Carbon::parse($stolen['report_due_at'])->toDateTimeString());
        $unscannable = $this->api('POST', 'regulatory-incidents', ['type' => 'unscannable_carrier', 'description' => 'DataMatrix smudged on 3 packs',
            'batch_number' => 'mp-amx-1'])->assertCreated()->json('data');
        $this->assertSame('MP-AMX-1', $unscannable['batch_number']);

        $this->travel(25)->hours();
        $this->assertSame([$stolen['id']], collect($this->api('GET', 'regulatory-incidents?overdue=1')->json('data'))->pluck('id')->all());
        $reported = $this->api('POST', "regulatory-incidents/{$stolen['id']}/report", ['authority_reference' => 'NAFDAC-PV-2026-0099'])->assertOk()->json('data');
        $this->assertSame(['reported', true], [$reported['status'], $reported['reported_late']]);
        $this->api('POST', "regulatory-incidents/{$stolen['id']}/report", ['authority_reference' => 'x'])->assertStatus(409);

        [$cashier] = $this->organizationMember(['pos_cashier'], organization: $org);
        $this->actingAsApi($cashier, $org)->api('GET', 'regulatory-incidents')->assertForbidden();
    }

    /**
     * The distributor holds lot MP-AMX-1 (2000 capsules), ships 120 to a pharmacy on the platform
     * and 40 to a clinic outside the platform, and has 20 reserved for a confirmed order.
     */
    private function recallScenario(): array
    {
        $s = $this->listedSupplier();
        $listing = $this->listing($s, $s['amox']);
        $b = $this->tradingBuyer($s);
        $order = $this->actingAsApi($b['buyer'], $b['org'])->api('POST', 'marketplace/orders', [
            'supplier_organization_id' => $s['org']->id, 'delivery_warehouse_id' => $b['warehouse']->id, 'lines' => [['listing_id' => $listing, 'quantity' => '10']],
        ])->json('data');
        $this->api('POST', "marketplace/orders/{$order['id']}/submit")->assertOk();
        $this->actingAsApi($s['owner'], $s['org'])->api('POST', "marketplace/orders/{$order['id']}/accept")->assertOk();
        $shipment = $this->api('POST', "marketplace/orders/{$order['id']}/shipments", ['lines' => [['order_line_id' => $order['lines'][0]['id'], 'quantity' => '6']]], $this->idem())->json('data');
        $this->actingAsApi($b['buyer'], $b['org'])->api('POST', "marketplace/shipments/{$shipment['id']}/receive", [], $this->idem())->assertOk();

        $offPlatform = $this->inTenant($s['org'], fn () => Customer::query()->create(['code' => 'OFF1', 'name' => 'Off-platform Clinic', 'type' => 'clinic', 'currency_code' => 'NGN']));
        $this->actingAsApi($s['owner'], $s['org']);
        $so = $this->api('POST', 'sales-orders', ['customer_id' => $offPlatform->id, 'warehouse_id' => $s['main']->id,
            'lines' => [['product_id' => $s['amox']->id, 'package_level_code' => 'pack', 'quantity' => '2', 'unit_price' => '1200']]], $this->idem())->assertCreated()->json('data');
        $this->api('POST', "sales-orders/{$so['id']}/confirm")->assertOk();
        $this->api('POST', "sales-orders/{$so['id']}/deliveries", ['lines' => [['sales_order_line_id' => $so['lines'][0]['id'], 'quantity' => '2']]], $this->idem())->assertCreated();

        return ['s' => $s, 'b' => $b, 'offPlatform' => $offPlatform];
    }

    private function recallPayload(array $t): array
    {
        return ['product_id' => $t['s']['amox']->id, 'batch_numbers' => ['mp-amx-1'], 'title' => 'Out-of-specification dissolution', 'reason' => 'Dissolution below specification at 6 months stability.',
            'classification' => 'class_ii', 'depth' => 'retail', 'authority' => 'NAFDAC', 'regulatory_reference' => 'SYNTHETIC-RCL-0001',
            'quarantine_instructions' => 'Stop sale, quarantine all units, return to the distributor.'];
    }

    private function idem(): array
    {
        return ['Idempotency-Key' => 'rc-'.Str::lower(Str::random(16))];
    }
}

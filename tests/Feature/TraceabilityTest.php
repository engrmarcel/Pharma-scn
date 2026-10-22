<?php

namespace Tests\Feature;

use App\Domain\Catalogue\Models\ProductIdentifier;
use App\Domain\Inventory\Models\Batch;
use App\Domain\Traceability\Models\TraceEvent;
use App\Domain\Traceability\Models\TraceScan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\Concerns\InteractsWithMarketplace;
use Tests\FeatureTestCase;

/**
 * Phase 7a acceptance (spec §31 Phase 7, §16):
 *   a test batch is traced through recorded supply-chain events;
 *   corrective events preserve history (and tampering is detectable);
 *   recorded evidence is distinguished from independently verified authenticity;
 *   recalled and expired stock shows as such when scanned; QR codes reveal nothing confidential.
 */
class TraceabilityTest extends FeatureTestCase
{
    use InteractsWithMarketplace;

    public function test_a_batch_is_traced_across_organizations_through_recorded_events(): void
    {
        $t = $this->tracedLot();
        $lot = ['product_id' => $t['s']['amox']->id, 'batch_number' => 'mp-amx-1'];

        // The supplier sees its own history and the buyer's receipt from it.
        $supplierView = collect($this->actingAsApi($t['s']['owner'], $t['s']['org'])->api('GET', 'trace/lots?'.http_build_query($lot))->assertOk()->json('data.events'));
        $this->assertEquals(['batch_registered', 'stock_receipt', 'shipment_dispatch', 'pharmacy_receipt'], $supplierView->pluck('event_type')->all());
        $dispatch = $supplierView->firstWhere('event_type', 'shipment_dispatch');
        $this->assertSame(['120.0000', $t['s']['org']->id, $t['b']['org']->id], [$dispatch['quantity'], $dispatch['source']['organization_id'], $dispatch['destination']['organization_id']]);
        $this->assertSame('corroborated', $dispatch['verification']['state'], 'the buyer recorded the matching receipt');
        $this->assertSame('recorded', $supplierView->firstWhere('event_type', 'stock_receipt')['verification']['state']);

        // The buyer quarantines its stock of the lot: its own event, invisible to the supplier.
        $buyerBatch = $this->inTenant($t['b']['org'], fn () => Batch::query()->where('batch_number', 'MP-AMX-1')->sole());
        $this->actingAsApi($t['b']['owner'], $t['b']['org'])->api('POST', "batches/{$buyerBatch->id}/quarantine", ['reason' => 'Damaged cartons'])->assertOk();
        $buyerView = collect($this->api('GET', 'trace/lots?'.http_build_query($lot))->json('data.events'));
        $this->assertEqualsCanonicalizing(['shipment_dispatch', 'batch_registered', 'pharmacy_receipt', 'quarantine'], $buyerView->pluck('event_type')->all());
        $this->assertFalse($buyerView->contains('event_type', 'stock_receipt'), 'the supplier\'s internal receipt is not disclosed');
        $this->assertSame('120.0000', $buyerView->firstWhere('event_type', 'quarantine')['quantity']);
        $this->assertCount(4, $this->actingAsApi($t['s']['owner'], $t['s']['org'])->api('GET', 'trace/lots?'.http_build_query($lot))->json('data.events'));

        // Platform compliance sees the whole chain; an unrelated organization sees nothing.
        $officer = $this->platformUser('compliance_officer');
        $this->assertCount(6, $this->actingAsApi($officer)->api('GET', 'admin/trace/lots?'.http_build_query($lot))->assertOk()->json('data.events'));
        [$outsider, $otherOrg] = $this->marketplaceOrg('pharmacy');
        $this->actingAsApi($outsider, $otherOrg)->api('GET', 'trace/lots?'.http_build_query($lot))->assertOk()->assertJsonPath('data.events', []);
    }

    public function test_corrections_and_reversals_preserve_history_and_tampering_breaks_the_chain(): void
    {
        $t = $this->tracedLot();
        $this->actingAsApi($t['b']['owner'], $t['b']['org']);
        $receipt = collect($this->api('GET', 'trace/events?event_type=pharmacy_receipt')->json('data'))->sole();

        $correction = $this->api('POST', "trace/events/{$receipt['id']}/correct", ['reason' => 'Received into the dispensary store, not the main store',
            'changes' => ['destination_location' => 'DISPENSARY']])->assertCreated()->json('data');
        $this->assertSame(['correction', $receipt['id'], 'DISPENSARY'], [$correction['event_type'], $correction['corrects_event_id'], $correction['destination']['location']]);
        $original = $this->api('GET', "trace/events/{$receipt['id']}")->json('data');
        $this->assertSame([$correction['id']], $original['superseded_by']);
        $this->assertSame('STORE', $original['destination']['location'], 'the original is never edited');

        $reversal = $this->api('POST', "trace/events/{$correction['id']}/reverse", ['reason' => 'Correction entered on the wrong lot'])->assertCreated()->json('data');
        $this->api('POST', "trace/events/{$reversal['id']}/reverse", ['reason' => 'x'])->assertStatus(409);
        $this->api('POST', "trace/events/{$correction['id']}/reverse", ['reason' => 'again'])->assertStatus(409);

        // Only the recorder corrects; the supplier can see the receipt but not change it.
        $this->actingAsApi($t['s']['owner'], $t['s']['org'])->api('POST', "trace/events/{$receipt['id']}/correct", ['reason' => 'x', 'changes' => ['quantity' => '1']])
            ->assertStatus(403)->assertJsonPath('error.code', 'trace_event_not_yours');

        // The application refuses edits; an edit made behind its back is detected.
        $this->actingAsApi($t['b']['owner'], $t['b']['org'])->api('GET', 'trace/integrity')->assertOk()->assertJsonPath('data.intact', true);
        $this->expectExceptionObject(new LogicException('Traceability events are append-only. Record a correction instead.'));
        try {
            TraceEvent::query()->findOrFail($receipt['id'])->update(['quantity' => '1']);
        } finally {
            $sequence = DB::table('trace_events')->where('id', $receipt['id'])->value('sequence');
            DB::table('trace_events')->where('id', $receipt['id'])->update(['quantity' => '1.0000']);
            $integrity = $this->api('GET', 'trace/integrity')->json('data');
            $this->assertSame([false, $sequence], [$integrity['intact'], $integrity['first_broken_sequence']]);
        }
    }

    public function test_recorded_evidence_is_distinguished_from_independent_verification(): void
    {
        $t = $this->tracedLot();
        $dispatch = collect($this->actingAsApi($t['s']['owner'], $t['s']['org'])->api('GET', 'trace/events?event_type=shipment_dispatch')->json('data'))->sole();
        $verify = ['level' => 'independently_verified', 'method' => 'document_review', 'evidence' => 'Waybill and signed delivery note reviewed.'];

        $this->api('POST', "admin/trace/events/{$dispatch['id']}/verifications", $verify)->assertForbidden();
        $officer = $this->platformUser('compliance_officer');
        $this->actingAsApi($officer)->api('POST', "admin/trace/events/{$dispatch['id']}/verifications", $verify)->assertCreated();
        $verification = $this->actingAsApi($officer)->api('GET', "admin/trace/events/{$dispatch['id']}")->assertOk()->json('data.verification');
        $this->assertSame(['independently_verified', 'document_review'], [$verification['state'], $verification['method']]);
        $this->actingAsApi($officer)->api('POST', "admin/trace/events/{$dispatch['id']}/verifications", ['level' => 'disputed', 'method' => 'physical_inspection',
            'evidence' => 'Seals on the delivered cartons did not match.'])->assertCreated();
        $this->assertSame('disputed', $this->actingAsApi($t['s']['owner'], $t['s']['org'])->api('GET', "trace/events/{$dispatch['id']}")->json('data.verification.state'));
        $this->assertSame(2, DB::table('trace_event_verifications')->where('trace_event_id', $dispatch['id'])->where('level', '!=', 'corroborated')->count(), 'history kept');
    }

    public function test_qr_codes_serials_and_scans_report_status_without_confidential_data(): void
    {
        $t = $this->tracedLot();
        $product = $t['s']['amox'];
        $this->actingAsApi($t['s']['owner'], $t['s']['org']);

        // Batch QR: an opaque URL; the public answer names no organization and no price.
        $qr = $this->api('POST', 'trace/identifiers', ['kind' => 'batch', 'product_id' => $product->id, 'batch_number' => 'MP-AMX-1'])->assertCreated()->json('data');
        $this->assertMatchesRegularExpression('#/t/[A-Za-z0-9]{22}$#', $qr['url']);
        $this->assertStringContainsString('<svg', $this->get("/api/v1/trace/identifiers/{$qr['id']}/qr", ['X-Organization-Id' => $t['s']['org']->id])->assertOk()->getContent());
        $this->app['auth']->forgetGuards();
        $public = $this->getJson('/api/v1/trace/scan?code='.urlencode($qr['url']))->assertOk();
        $this->assertSame(['ok', 'MP-AMX-1'], [$public->json('data.result'), $public->json('data.batch_number')]);
        $this->assertStringContainsString('does not by itself prove', $public->json('data.disclaimer'));
        foreach ([$t['s']['org']->name, $t['b']['org']->name, '1200'] as $secret) {
            $this->assertStringNotContainsString($secret, $public->getContent());
        }
        $this->get("/t/{$qr['token']}")->assertOk()->assertSee('Pack check')->assertSee('No problem recorded');
        $this->assertNotNull(TraceScan::query()->latest('id')->value('client_hash'));
        $this->assertNull(TraceScan::query()->where('client_hash', '127.0.0.1')->first(), 'the address itself is not stored');

        // Serials need a GTIN; duplicates are refused across the platform.
        $serials = ['product_id' => $product->id, 'batch_number' => 'MP-AMX-1', 'serials' => ['SN0001', 'SN0002']];
        $this->actingAsApi($t['s']['owner'], $t['s']['org'])->api('POST', 'trace/serials', $serials)->assertStatus(422)->assertJsonPath('error.code', 'gtin_required');
        ProductIdentifier::query()->create(['product_id' => $product->id, 'type' => 'gtin', 'value' => '6901234000016', 'is_primary' => true]);
        $created = $this->api('POST', 'trace/serials', $serials)->assertCreated()->json('data');
        $this->assertSame('(01)06901234000016(17)'.now()->addYears(2)->format('ymd').'(10)MP-AMX-1(21)SN0001', $created[0]['gs1']);
        $this->api('POST', 'trace/serials', ['serials' => ['SN0002', 'SN0003']] + $serials)->assertStatus(409)->assertJsonPath('error.details.serials', ['SN0002']);
        $this->assertSame('identifiers_commissioned', collect($this->api('GET', 'trace/events?event_type=identifiers_commissioned')->json('data'))->sole()['event_type']);

        // A pharmacy scans the DataMatrix (raw, with group separators) and dispenses the pack.
        $raw = '0106901234000016'.'17'.now()->addYears(2)->format('ymd').'10MP-AMX-1'."\x1D".'21SN0001';
        $this->actingAsApi($t['b']['owner'], $t['b']['org']);
        $scan = $this->api('POST', 'trace/scan', ['code' => $raw])->assertOk()->json('data');
        $this->assertSame(['ok', 'SN0001', 'STORE', '120.0000'], [$scan['result'], $scan['serial'], $scan['your_stock'][0]['warehouse_code'], $scan['your_stock'][0]['quantity']]);
        $this->api('POST', 'trace/dispense', ['code' => $raw])->assertOk()->assertJsonPath('data.status', 'dispensed');
        $this->api('POST', 'trace/dispense', ['code' => $raw])->assertStatus(409);
        $this->assertSame('dispensed', $this->getJson('/api/v1/trace/scan?code='.urlencode('(01)06901234000016(10)MP-AMX-1(21)SN0001'))->json('data.result'));
        $this->assertSame('unregistered', $this->getJson('/api/v1/trace/scan?code='.urlencode('(01)06901234000016(10)MP-AMX-1(21)FAKE99'))->json('data.result'));
        $this->assertNotEmpty($this->getJson('/api/v1/trace/scan?code='.urlencode('(01)06901234000016(17)300101(10)MP-AMX-1'))->json('data.warnings'), 'printed expiry differs');

        // A logistic unit: revoking it withdraws the packs inside.
        $this->actingAsApi($t['s']['owner'], $t['s']['org']);
        $sscc = $this->api('POST', 'trace/identifiers', ['kind' => 'sscc', 'serial' => '106901234000000017'])->assertCreated()->json('data');
        $this->api('POST', "trace/identifiers/{$sscc['id']}/aggregate", ['child_ids' => [$created[1]['id']]])->assertOk()->assertJsonPath('data.aggregated', 1);
        $this->api('POST', "trace/identifiers/{$sscc['id']}/revoke", ['reason' => 'Pallet damaged in transit'])->assertOk();
        $this->assertSame('revoked', $this->getJson('/api/v1/trace/scan?code='.$created[1]['token'])->json('data.result'));

        // Recalled and expired lots are reported as such.
        $this->inTenant($t['s']['org'], fn () => Batch::query()->where('batch_number', 'MP-AMX-1')->update(['recall_status' => 'recalled']));
        $this->assertSame('recalled', $this->getJson('/api/v1/trace/scan?code='.urlencode($qr['url']))->json('data.result'));
        $this->receive($t['s']['org'], $t['s']['main'], $product, 'OLD-LOT', '5', '-1 month', ['quality_status' => 'quarantined']);
        $this->assertSame('expired', $this->getJson('/api/v1/trace/scan?code='.urlencode('(01)06901234000016(10)OLD-LOT'))->json('data.result'));
        $this->assertSame('unknown', $this->getJson('/api/v1/trace/scan?code='.urlencode('(01)06901234000023(10)X'))->json('data.result'));
    }

    public function test_nafdac_requirements_glns_epcis_export_and_the_single_pack_code_rule(): void
    {
        $t = $this->tracedLot();
        $this->actingAsApi($t['s']['owner'], $t['s']['org']);

        // Reg. 11: GLNs for the organization and its locations, with a valid GS1 check digit.
        $this->api('PATCH', 'organization', ['gln' => '6901234000012'])->assertStatus(422);
        $this->api('PATCH', 'organization', ['gln' => '6901234000023'])->assertOk();
        $this->api('PATCH', "warehouses/{$t['s']['main']->id}", ['gln' => '6901234500011'])->assertOk();
        $this->actingAsApi($t['b']['owner'], $t['b']['org'])->api('PATCH', 'organization', ['gln' => '6901234000023'])->assertStatus(422);
        $this->api('PATCH', 'organization', ['gln' => '6901234600018'])->assertOk();
        ProductIdentifier::query()->create(['product_id' => $t['s']['amox']->id, 'type' => 'gtin', 'value' => '6901234000016', 'is_primary' => true]);

        // Reg. 7(1): a platform QR code must not be printed on packs in Nigeria.
        $this->actingAsApi($t['s']['owner'], $t['s']['org']);
        $this->api('POST', 'trace/identifiers', ['kind' => 'batch', 'product_id' => $t['s']['amox']->id, 'batch_number' => 'MP-AMX-1'])->assertCreated()
            ->assertJsonPath('meta.regulatory_notice', fn ($notice) => str_contains($notice, 'Do not print this QR code on the product pack'));

        // A new dispatch after the GLNs exist carries them; the EPCIS export uses GS1 Digital Link identifiers.
        $this->receive($t['s']['org'], $t['s']['main'], $t['s']['amox'], 'MP-AMX-2', '100', '+2 years');
        $export = $this->api('GET', 'trace/export/epcis?batch_number=MP-AMX-2')->assertOk();
        $this->assertStringContainsString('application/ld+json', $export->headers->get('Content-Type'));
        $events = $export->json('epcisBody.eventList');
        $receiving = collect($events)->firstWhere('bizStep', 'receiving');
        $this->assertSame(['ObjectEvent', 'OBSERVE', 'sellable_accessible', 'https://id.gs1.org/01/06901234000016/10/MP-AMX-2', 100, 'https://id.gs1.org/414/6901234500011', '+01:00'],
            [$receiving['type'], $receiving['action'], $receiving['disposition'], $receiving['quantityList'][0]['epcClass'], (int) $receiving['quantityList'][0]['quantity'],
                $receiving['bizLocation']['id'], $receiving['eventTimeZoneOffset']]);
        $this->assertSame(0, $export->json('pharma:meta.incomplete'));

        // Corrections are exported as an EPCIS error declaration on the original event.
        $this->api('POST', "trace/events/{$receiving['pharma:eventId']}/correct", ['reason' => 'Counted twice', 'changes' => ['quantity' => '90']])->assertCreated();
        $corrected = collect($this->api('GET', 'trace/export/epcis?batch_number=MP-AMX-2')->json('epcisBody.eventList'))->firstWhere('pharma:eventId', $receiving['pharma:eventId']);
        $this->assertSame('incorrect_data', $corrected['errorDeclaration']['reason']);
        $this->assertCount(1, $corrected['errorDeclaration']['correctiveEventIDs']);
    }

    /** A distributor ships 6 packs (120 capsules) of lot MP-AMX-1 to a pharmacy through the marketplace; the pharmacy receives them. */
    private function tracedLot(): array
    {
        $s = $this->listedSupplier();
        $listing = $this->listing($s, $s['amox']);
        $b = $this->tradingBuyer($s);
        $order = $this->actingAsApi($b['buyer'], $b['org'])->api('POST', 'marketplace/orders', [
            'supplier_organization_id' => $s['org']->id, 'delivery_warehouse_id' => $b['warehouse']->id, 'lines' => [['listing_id' => $listing, 'quantity' => '10']],
        ])->json('data');
        $this->api('POST', "marketplace/orders/{$order['id']}/submit")->assertOk();
        $this->actingAsApi($s['owner'], $s['org'])->api('POST', "marketplace/orders/{$order['id']}/accept")->assertOk();
        $shipment = $this->api('POST', "marketplace/orders/{$order['id']}/shipments", ['lines' => [['order_line_id' => $order['lines'][0]['id'], 'quantity' => '6']]],
            ['Idempotency-Key' => 'tr-'.Str::lower(Str::random(16))])->assertCreated()->json('data');
        $this->actingAsApi($b['buyer'], $b['org'])->api('POST', "marketplace/shipments/{$shipment['id']}/receive", [], ['Idempotency-Key' => 'tr-'.Str::lower(Str::random(16))])->assertOk();

        return ['s' => $s, 'b' => $b];
    }
}

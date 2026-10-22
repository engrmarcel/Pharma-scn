<?php

namespace Tests\Feature;

use App\Domain\Integration\Webhooks\OutboundEvents;
use App\Domain\Logistics\Events\TemperatureExcursionDetected;
use App\Domain\Logistics\Models\ShipmentEvent;
use App\Domain\Logistics\Models\TemperatureExcursion;
use App\Domain\Parties\Models\Customer;
use App\Notifications\TemperatureExcursionNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use Tests\Concerns\InteractsWithMarketplace;
use Tests\FeatureTestCase;

/**
 * Phase 7c acceptance (spec §18): shipments over the stock documents, carrier, driver and
 * vehicle, tracking, proof of delivery, partial and failed delivery, return to origin,
 * reconciliation, temperature records, excursion alerts and their assessment.
 */
class LogisticsTest extends FeatureTestCase
{
    use InteractsWithMarketplace;

    public function test_a_cold_chain_delivery_is_carried_tracked_received_and_reconciled(): void
    {
        Notification::fake();
        Storage::fake(config('platform.attachments.disk'));
        $t = $this->scenario();
        $this->actingAsApi($t['s']['owner'], $t['s']['org']);

        // Planned from the sales delivery: items by batch, cold chain from the product.
        $shipment = $this->api('POST', 'logistics/shipments', ['source_type' => 'sales_delivery', 'source_id' => $t['delivery'],
            'planned_delivery_at' => now()->addDay()->toIso8601String()])->assertCreated()->json('data');
        $this->assertSame(['planned', 'shipper', '2.00', '8.00'], [$shipment['status'], $shipment['your_role'], $shipment['cold_chain']['min_c'], $shipment['cold_chain']['max_c']]);
        $this->assertSame([['MP-AMX-1', '40.0000']], collect($shipment['items'])->map(fn ($i) => [$i['batch_number'], $i['quantity']])->all());
        $this->api('POST', 'logistics/shipments', ['source_type' => 'sales_delivery', 'source_id' => $t['delivery']])->assertStatus(409)->assertJsonPath('error.code', 'shipment_exists');

        // Dispatch needs a carrier or driver, and cold-chain equipment.
        $id = $shipment['id'];
        $this->api('POST', "logistics/shipments/{$id}/dispatch")->assertStatus(422)->assertJsonPath('error.code', 'shipment_unassigned');
        $this->api('POST', "logistics/shipments/{$id}/assign", ['carrier_organization_id' => $t['s']['org']->id])->assertStatus(422)->assertJsonPath('error.code', 'carrier_invalid');
        $this->api('POST', "logistics/shipments/{$id}/assign", ['carrier_organization_id' => $t['carrier']->id, 'driver_user_id' => $t['driver']->id])->assertOk();
        $this->actingAsApi($t['driver'], $t['carrier'])->api('POST', "logistics/shipments/{$id}/dispatch")->assertStatus(422)->assertJsonPath('error.code', 'cold_chain_equipment_required');
        $vehicle = $this->actingAsApi($t['carrierOwner'], $t['carrier'])->api('POST', 'logistics/vehicles', ['registration' => 'lag-123-xy', 'refrigerated' => true])->assertCreated()->json('data');
        $this->actingAsApi($t['s']['owner'], $t['s']['org'])->api('POST', "logistics/shipments/{$id}/assign", ['vehicle_id' => $vehicle['id'], 'temperature_logger_id' => 'LOG-77'])
            ->assertOk()->assertJsonPath('data.vehicle.registration', 'LAG-123-XY');

        // The carrier's driver dispatches, tracks and records temperatures; one excursion of two readings.
        $this->actingAsApi($t['driver'], $t['carrier']);
        $this->assertSame([$id], collect($this->api('GET', 'logistics/shipments?mine=1')->json('data'))->pluck('id')->all());
        $this->api('POST', "logistics/shipments/{$id}/dispatch")->assertOk()->assertJsonPath('data.status', 'dispatched');
        $this->api('POST', "logistics/shipments/{$id}/events", ['event_type' => 'location_update', 'latitude' => 6.45, 'longitude' => 3.39, 'location_text' => 'Ikeja'])->assertCreated();
        $base = now()->subMinutes(40);
        $readings = collect([5.1, 9.4, 11.2, 6.0])->map(fn ($c, $i) => ['recorded_at' => $base->copy()->addMinutes($i * 10)->toIso8601String(), 'temperature_c' => $c])->all();
        $this->api('POST', "logistics/shipments/{$id}/temperatures", ['source' => 'logger', 'readings' => $readings])->assertCreated()
            ->assertJsonPath('data', ['stored' => 4, 'duplicates' => 0, 'excursions_opened' => 1]);
        $this->api('POST', "logistics/shipments/{$id}/temperatures", ['source' => 'logger', 'readings' => $readings])->assertJsonPath('data.duplicates', 4);
        $excursion = TemperatureExcursion::query()->sole();
        $this->assertSame(['9.40', '11.20', 2, 20], [$excursion->lowest_c, $excursion->highest_c, $excursion->readings, $excursion->minutes()]);
        Notification::assertSentTo($t['s']['owner'], TemperatureExcursionNotification::class, fn ($n) => $n->highest === '11.20');
        Notification::assertNotSentTo($t['carrierOwner'], TemperatureExcursionNotification::class);
        $this->assertSame('logistics.temperature_excursion', OutboundEvents::typeFor(new TemperatureExcursionDetected($id, $excursion->id, $t['s']['org']->id)));
        $this->assertSame(['LOG-77', '11.20'], [OutboundEvents::payload(new TemperatureExcursionDetected($id, $excursion->id, $t['s']['org']->id))[2]['temperature_logger_id'],
            (string) OutboundEvents::payload(new TemperatureExcursionDetected($id, $excursion->id, $t['s']['org']->id))[2]['highest_c']]);

        // Only the shipper assesses an excursion.
        $this->api('POST', "logistics/shipments/{$id}/excursions/{$excursion->id}/assess", ['assessment' => 'product_usable', 'notes' => 'x'])->assertStatus(403);

        // Partial delivery with a signature: an unassessed excursion is flagged on the proof.
        $item = $shipment['items'][0]['id'];
        $delivered = $this->withHeaders([config('platform.tenancy.header') => $t['carrier']->id])->post("/api/v1/logistics/shipments/{$id}/deliver", [
            'received_by_name' => 'Ada Okafor', 'received_by_role' => 'Pharmacist', 'condition' => 'temperature_concern',
            'items' => [['shipment_item_id' => $item, 'delivered_quantity' => '30', 'discrepancy_note' => '10 capsules (one carton) refused: warm']],
            'signature' => UploadedFile::fake()->image('signature.png', 300, 120),
        ], ['Accept' => 'application/json'])->assertOk()->json('data');
        $this->assertSame(['partially_delivered', true, '30.0000'], [$delivered['status'], $delivered['proof_of_delivery']['cold_chain_unresolved'], $delivered['items'][0]['delivered_quantity']]);
        $this->assertNotNull($delivered['proof_of_delivery']['signature_attachment_id']);
        $this->assertStringContainsString('credit note', $delivered['next_steps'][0]);
        $this->api('POST', "logistics/shipments/{$id}/return", ['items' => [['shipment_item_id' => $item, 'returned_quantity' => '11']]])->assertStatus(422);
        $back = $this->api('POST', "logistics/shipments/{$id}/return", ['notes' => 'Refused goods back at the distributor'])->assertOk()->json('data');
        $this->assertSame(['partially_delivered', '10.0000'], [$back['status'], $back['items'][0]['returned_quantity']]);

        // The shipper sees the whole history and reconciles.
        $this->actingAsApi($t['s']['owner'], $t['s']['org']);
        $this->api('POST', "logistics/shipments/{$id}/excursions/{$excursion->id}/assess", ['assessment' => 'quarantine', 'notes' => 'Refused part quarantined pending QA'])
            ->assertOk()->assertJsonPath('data.status', 'assessed');
        $history = collect($this->api('GET', "logistics/shipments/{$id}")->json('data.events'));
        $this->assertEqualsCanonicalizing(['created', 'dispatched', 'location_update', 'temperature_excursion', 'partially_delivered', 'returned_to_origin', 'note'],
            $history->pluck('event_type')->all());
        $this->assertSame('partner', $history->firstWhere('event_type', 'dispatched')['recorded_by']);
        $report = $this->api('GET', 'logistics/reconciliation?from='.now()->toDateString().'&to='.now()->toDateString())->assertOk()->json('data');
        $this->assertSame(['40.0000', '30.0000', '10.0000'], array_values($report['quantities']));
        $this->assertSame([[], 100, ['shipments' => 1, 'excursions' => 1, 'unassessed' => 0]], [$report['discrepancies'], $report['on_time_percent'], $report['cold_chain']]);

        // Tracking history is append-only.
        $this->expectException(LogicException::class);
        ShipmentEvent::query()->first()->update(['notes' => 'rewritten']);
    }

    public function test_failed_deliveries_return_and_the_shipment_stays_with_its_participants(): void
    {
        $t = $this->scenario();
        $this->actingAsApi($t['s']['owner'], $t['s']['org']);

        // A manual shipment of a product without cold chain: temperatures are kept, no excursion.
        $manual = $this->api('POST', 'logistics/shipments', ['source_type' => 'manual', 'origin_warehouse_id' => $t['s']['main']->id, 'destination_name' => 'Ikorodu depot',
            'items' => [['product_id' => $t['s']['rx']->id, 'batch_number' => 'mp-cef-1', 'quantity' => '24']]])->assertCreated()->json('data');
        $this->assertNull($manual['cold_chain']);
        $this->api('POST', 'logistics/shipments', ['source_type' => 'manual', 'origin_warehouse_id' => $t['s']['main']->id, 'destination_name' => 'X',
            'items' => [['product_id' => $t['s']['rx']->id, 'batch_number' => 'NOPE', 'quantity' => '1']]])->assertStatus(422)->assertJsonPath('error.code', 'batch_not_found');
        $this->api('POST', "logistics/shipments/{$manual['id']}/assign", ['driver_name' => 'Musa Bello', 'driver_phone' => '+2348000000000', 'vehicle_registration' => 'KJA-1'])->assertOk();
        $this->api('POST', "logistics/shipments/{$manual['id']}/dispatch")->assertOk();
        $this->api('POST', "logistics/shipments/{$manual['id']}/cancel", ['reason' => 'x'])->assertStatus(409)->assertJsonPath('error.code', 'shipment_not_cancellable');
        $this->api('POST', "logistics/shipments/{$manual['id']}/temperatures", ['source' => 'manual', 'readings' => [['recorded_at' => now()->toIso8601String(), 'temperature_c' => 31]]])
            ->assertJsonPath('data', ['stored' => 1, 'duplicates' => 0, 'excursions_opened' => 0]);

        // Failed at the door, everything comes back; nothing is unaccounted.
        $this->api('POST', "logistics/shipments/{$manual['id']}/fail", ['reason' => 'Depot closed'])->assertOk()->assertJsonPath('data.status', 'failed');
        $this->api('POST', "logistics/shipments/{$manual['id']}/deliver", ['received_by_name' => 'x', 'condition' => 'good'])->assertStatus(409);
        $back = $this->api('POST', "logistics/shipments/{$manual['id']}/return")->assertOk()->json('data');
        $this->assertSame(['returned_to_origin', '24.0000'], [$back['status'], $back['items'][0]['returned_quantity']]);

        // A failed delivery that is not brought back shows up as unaccounted.
        $lost = $this->api('POST', 'logistics/shipments', ['source_type' => 'sales_delivery', 'source_id' => $t['delivery']])->assertCreated()->json('data');
        $this->api('POST', "logistics/shipments/{$lost['id']}/assign", ['carrier_name' => 'Off-platform courier', 'temperature_logger_id' => 'LOG-1'])->assertOk();
        $this->api('POST', "logistics/shipments/{$lost['id']}/dispatch")->assertOk();
        $this->api('POST', "logistics/shipments/{$lost['id']}/fail", ['reason' => 'Vehicle broke down'])->assertOk();
        $report = $this->api('GET', 'logistics/reconciliation?from='.now()->toDateString().'&to='.now()->toDateString())->json('data');
        $this->assertSame([[$lost['number'], '40.0000']], collect($report['discrepancies'])->map(fn ($d) => [$d['number'], $d['unaccounted']])->all());
        $this->assertSame([$lost['number']], collect($report['failed'])->pluck('number')->all());

        // Planned shipments can be cancelled; a second one for the same delivery is then allowed.
        $planned = $this->api('POST', 'logistics/shipments', ['source_type' => 'manual', 'origin_warehouse_id' => $t['s']['main']->id, 'destination_name' => 'Y',
            'items' => [['product_id' => $t['s']['rx']->id, 'batch_number' => 'MP-CEF-1', 'quantity' => '1']]])->json('data');
        $this->api('POST', "logistics/shipments/{$planned['id']}/cancel", ['reason' => 'Customer collected'])->assertOk()->assertJsonPath('data.status', 'cancelled');

        // Not visible to an unrelated carrier or organization; a driver cannot plan; the carrier cannot cancel.
        [$otherOwner, $other] = $this->organizationMember(orgAttributes: ['type' => 'logistics_provider'], verified: true);
        $this->actingAsApi($otherOwner, $other)->api('GET', "logistics/shipments/{$lost['id']}")->assertNotFound();
        $this->actingAsApi($t['driver'], $t['carrier'])->api('POST', 'logistics/shipments', ['source_type' => 'manual'])->assertForbidden();
        $this->actingAsApi($t['s']['owner'], $t['s']['org'])->api('POST', "logistics/shipments/{$planned['id']}/assign", ['carrier_organization_id' => $t['carrier']->id])->assertStatus(409);
        $draft = $this->api('POST', 'logistics/shipments', ['source_type' => 'manual', 'origin_warehouse_id' => $t['s']['main']->id, 'destination_name' => 'Z',
            'items' => [['product_id' => $t['s']['rx']->id, 'batch_number' => 'MP-CEF-1', 'quantity' => '1']]])->json('data');
        $this->api('POST', "logistics/shipments/{$draft['id']}/assign", ['carrier_organization_id' => $t['carrier']->id])->assertOk();
        $this->actingAsApi($t['carrierOwner'], $t['carrier'])->api('POST', "logistics/shipments/{$draft['id']}/cancel", ['reason' => 'x'])->assertStatus(403);
        $this->api('GET', 'logistics/shipments?role=carrier')->assertOk()->assertJsonCount(1, 'data');
    }

    /**
     * A distributor delivering 2 packs (40 capsules) of a cold-chain product to a clinic,
     * and a logistics provider on the platform with a driver.
     */
    private function scenario(): array
    {
        $s = $this->stockedSupplier();
        $s['amox']->forceFill(['requires_cold_chain' => true, 'temperature_min_c' => 2, 'temperature_max_c' => 8])->saveQuietly();
        $clinic = $this->inTenant($s['org'], fn () => Customer::query()->create(['code' => 'CL1', 'name' => 'Synthetic Demo Clinic', 'type' => 'clinic', 'currency_code' => 'NGN',
            'address_line1' => '1 Demo Road', 'city' => 'Lagos']));
        $this->actingAsApi($s['owner'], $s['org']);
        $so = $this->api('POST', 'sales-orders', ['customer_id' => $clinic->id, 'warehouse_id' => $s['main']->id,
            'lines' => [['product_id' => $s['amox']->id, 'package_level_code' => 'pack', 'quantity' => '2', 'unit_price' => '1200']]], $this->idem())->assertCreated()->json('data');
        $this->api('POST', "sales-orders/{$so['id']}/confirm")->assertOk();
        $delivery = $this->api('POST', "sales-orders/{$so['id']}/deliveries", ['lines' => [['sales_order_line_id' => $so['lines'][0]['id'], 'quantity' => '2']]], $this->idem())
            ->assertCreated()->json('data.id');

        [$carrierOwner, $carrier] = $this->organizationMember(orgAttributes: ['type' => 'cold_chain_logistics_provider', 'country_code' => 'NG'], verified: true);
        [$driver] = $this->organizationMember(['driver'], organization: $carrier);

        return compact('s', 'delivery', 'carrier', 'carrierOwner', 'driver');
    }

    private function idem(): array
    {
        return ['Idempotency-Key' => 'lg-'.Str::lower(Str::random(16))];
    }
}

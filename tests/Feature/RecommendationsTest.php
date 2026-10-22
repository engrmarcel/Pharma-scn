<?php

namespace Tests\Feature;

use App\Domain\Analytics\Models\Recommendation;
use App\Domain\Catalogue\Models\OrganizationProduct;
use App\Domain\Organizations\Models\OrganizationModule;
use App\Domain\Organizations\Models\Warehouse;
use App\Domain\Procurement\Models\PurchaseRequisition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithMarketplace;
use Tests\FeatureTestCase;

/**
 * Phase 8b acceptance: forecasts and recommendations are labelled, explainable and advisory;
 * they cannot independently perform financial, inventory or regulatory actions (spec §19.2).
 *
 * Demand history is written straight into the analytical fact table: synthetic facts for
 * twelve weeks of steady sales (30 capsules a day), which the ledger would otherwise produce.
 */
class RecommendationsTest extends FeatureTestCase
{
    use InteractsWithMarketplace;

    public function test_recommendations_are_generated_without_acting_and_a_person_turns_one_into_a_draft_requisition(): void
    {
        $t = $this->scenario();
        $this->actingAsApi($t['s']['owner'], $t['s']['org']);

        // Forecast: steady 210 capsules a week, labelled as a prediction with its method and accuracy.
        $f = $this->api('GET', 'analytics/forecast?product_id='.$t['s']['amox']->id.'&horizon_weeks=4')->assertOk()->json('data');
        $this->assertSame(['prediction', 'statistical-v1', 4, 210], [$f['kind'], $f['provider'], count($f['forecast']), $f['forecast'][0]['value']]);
        $this->assertNotEmpty($f['assumptions']);
        $this->api('GET', 'analytics/forecast?product_id='.$t['s']['rx']->id)->assertStatus(422)->assertJsonPath('error.code', 'forecast_insufficient_history');

        // Generating touches nothing but recommendation rows.
        $before = $this->businessCounts();
        $run = $this->api('POST', 'analytics/recommendations/generate')->assertOk()->json('data');
        $this->assertSame($before, $this->businessCounts());
        $this->assertSame([4, 4], [$run['open'], $run['created']]);

        $recs = collect($this->api('GET', 'analytics/recommendations')->assertOk()->json('data'))->keyBy('type');
        $this->assertEqualsCanonicalizing(['reorder', 'expiry_risk', 'integration_anomaly', 'traceability_anomaly'], $recs->keys()->all());
        $reorder = $recs['reorder'];
        $this->assertSame(['recommendation', 'warning', '1000', 'forecast', 14, 'default'], [$reorder['kind'], $reorder['severity'], $reorder['figures']['suggested_quantity'],
            $reorder['figures']['demand_basis'], $reorder['figures']['lead_time_days'], $reorder['figures']['lead_time_basis']]);
        $this->assertSame('1320', $reorder['figures']['expected_demand_cycle']);
        $this->assertStringContainsString('reorder level', $reorder['rationale']);
        $this->assertStringContainsString('does not change anything by itself', $reorder['label']);
        $this->assertSame(['prediction', 'warning', 'MP-CEF-9'], [$recs['expiry_risk']['kind'], $recs['expiry_risk']['severity'], $recs['expiry_risk']['figures']['batch_number']]);
        $this->assertSame(['anomaly', 'critical', 60.0], [$recs['integration_anomaly']['kind'], $recs['integration_anomaly']['severity'],
            (float) $recs['integration_anomaly']['figures']['problem_percent_24h']]);
        $this->assertSame(['critical', 3], [$recs['traceability_anomaly']['severity'], $recs['traceability_anomaly']['figures']['distinct_scanners']]);

        // The web dashboard lists them with the machine-generated label.
        $this->actingAs($t['s']['owner'])->withSession([config('platform.tenancy.session_key') => $t['s']['org']->id])->get('/analytics')->assertOk()
            ->assertSee('Recommendations')->assertSee('Machine-generated')->assertSee('Reorder Supplimox');

        // A rerun refreshes instead of duplicating.
        $this->assertSame(['created' => 0, 'refreshed' => 4], array_intersect_key($this->api('POST', 'analytics/recommendations/generate')->json('data'), array_flip(['created', 'refreshed'])));

        // Who sees what: area permissions per type; no analytics.view, no recommendations.
        [$manager] = $this->organizationMember(['sales_manager'], organization: $t['s']['org']);
        $this->actingAsApi($manager, $t['s']['org']);
        $this->assertEqualsCanonicalizing(['reorder', 'expiry_risk', 'traceability_anomaly'], collect($this->api('GET', 'analytics/recommendations')->json('data'))->pluck('type')->all(), 'no integrations.view, so no integration anomaly');
        $this->api('POST', "analytics/recommendations/{$reorder['id']}/accept")->assertForbidden();
        [$officer] = $this->organizationMember(['warehouse_officer'], organization: $t['s']['org']);
        $this->actingAsApi($officer, $t['s']['org'])->api('GET', 'analytics/recommendations')->assertForbidden();

        // A person accepts: a draft requisition in base units, nothing submitted, approved or ordered.
        $this->actingAsApi($t['s']['owner'], $t['s']['org']);
        $accepted = $this->api('POST', "analytics/recommendations/{$reorder['id']}/accept", ['notes' => 'Agreed with procurement'])->assertOk()->json('data');
        $this->assertSame(['accepted', 'purchase_requisition'], [$accepted['status'], $accepted['decision']['action_type']]);
        $requisition = PurchaseRequisition::acrossTenants()->with('lines')->findOrFail($accepted['decision']['action_id']);
        $this->assertSame(['draft', '1000.0000', $t['s']['main']->id], [$requisition->status, (string) $requisition->lines[0]->base_quantity, $requisition->warehouse_id]);
        $this->assertSame(0, DB::table('purchase_orders')->count());
        $this->api('POST', "analytics/recommendations/{$reorder['id']}/dismiss", ['reason' => 'x'])->assertStatus(409);

        // Dismissal needs a reason; the decision is audited.
        $this->api('POST', "analytics/recommendations/{$recs['expiry_risk']['id']}/dismiss")->assertStatus(422);
        $this->api('POST', "analytics/recommendations/{$recs['expiry_risk']['id']}/dismiss", ['reason' => 'Supplier takes it back next week'])->assertOk()->assertJsonPath('data.status', 'dismissed');
        $this->assertDatabaseHas('audit_logs', ['action' => 'recommendation.accepted', 'auditable_id' => $reorder['id']]);

        // Next run: the requisition counts as on order, so no new reorder; a cleared anomaly is superseded.
        DB::table('integration_inbox')->where('received_at', '>=', now()->subDay())->delete();
        $run = $this->api('POST', 'analytics/recommendations/generate')->json('data');
        $this->assertSame(1, $run['superseded']);
        $this->assertSame(0, Recommendation::acrossTenants()->where('type', 'reorder')->where('status', 'open')->count());
        $this->assertSame('superseded', Recommendation::acrossTenants()->where('type', 'integration_anomaly')->sole()->status);
    }

    public function test_recommendations_follow_locations_and_need_the_module(): void
    {
        $t = $this->scenario();
        $this->actingAsApi($t['s']['owner'], $t['s']['org'])->api('POST', 'analytics/recommendations/generate')->assertOk();

        [$annexOnly, , $membership] = $this->organizationMember(['inventory_manager'], organization: $t['s']['org']);
        $membership->forceFill(['all_locations' => false])->save();
        $membership->locationScopes()->create(['location_type' => 'warehouse', 'location_id' => $t['annex']->id]);
        $types = collect($this->actingAsApi($annexOnly, $t['s']['org'])->api('GET', 'analytics/recommendations')->assertOk()->json('data'))->pluck('type');
        $this->assertNotContains('reorder', $types->all(), 'the reorder is for the main warehouse');
        $this->assertContains('expiry_risk', $types->all());
        $reorder = Recommendation::acrossTenants()->where('type', 'reorder')->sole();
        $this->api('POST', "analytics/recommendations/{$reorder->id}/accept")->assertNotFound();

        $this->artisan('analytics:recommend')->assertSuccessful();
        OrganizationModule::query()->where('organization_id', $t['s']['org']->id)->where('module', 'analytics')->update(['enabled' => false]);
        $this->actingAsApi($t['s']['owner'], $t['s']['org'])->api('GET', 'analytics/recommendations')->assertForbidden();
    }

    /**
     * A distributor with analytics: Supplimox (2000 capsules, reorder level 2100, reorder
     * quantity 1000) selling 30 a day for twelve weeks; a Rocetrix lot of 100 expiring in
     * 40 days with no sales; a connector whose last day went wrong; and a dispensed serial
     * scanned again by three different scanners.
     */
    private function scenario(): array
    {
        $s = $this->stockedSupplier();
        OrganizationModule::query()->create(['organization_id' => $s['org']->id, 'module' => 'analytics', 'enabled' => true]);
        $annex = $this->inTenant($s['org'], fn () => Warehouse::query()->create(['code' => 'ANNEX', 'name' => 'Annex']));
        $this->receive($s['org'], $annex, $s['rx'], 'MP-CEF-9', '100', '+40 days', ['unit_cost' => '900']);
        $this->inTenant($s['org'], fn () => OrganizationProduct::query()->where('product_id', $s['amox']->id)->update(['reorder_level' => 2100, 'reorder_quantity' => 1000]));

        $rows = [];
        for ($d = 1; $d <= 84; $d++) {
            $rows[] = ['organization_id' => $s['org']->id, 'warehouse_id' => $s['main']->id, 'product_id' => $s['amox']->id,
                'day' => today()->startOfWeek()->subDays($d)->toDateString(), 'quantity_out' => 30, 'quantity_returned' => 0, 'movements' => 3];
        }
        DB::table('analytics_daily_demand')->insert($rows);

        $connector = (string) Str::ulid();
        DB::table('integration_connectors')->insert(['id' => $connector, 'organization_id' => $s['org']->id, 'code' => 'S4', 'name' => 'Synthetic ERP',
            'system_type' => 'sap_s4hana', 'inbound_transport' => 'rest_push', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $message = fn ($i, $status, $at) => ['organization_id' => $s['org']->id, 'connector_id' => $connector, 'message_id' => "m-{$i}", 'entity_type' => 'stock_level',
            'payload_hash' => hash('sha256', (string) $i), 'payload' => '{}', 'status' => $status, 'received_at' => $at];
        DB::table('integration_inbox')->insert(array_merge(
            array_map(fn ($i) => $message($i, 'processed', now()->subDays(5)), range(1, 40)),
            array_map(fn ($i) => $message(100 + $i, $i <= 6 ? 'quarantined' : 'processed', now()->subHours(2)), range(1, 10)),
        ));

        $serial = (string) Str::ulid();
        DB::table('trace_identifiers')->insert(['id' => $serial, 'organization_id' => $s['org']->id, 'kind' => 'serial', 'token' => Str::random(22), 'product_id' => $s['amox']->id,
            'batch_number' => 'MP-AMX-1', 'serial' => 'SN0001', 'status' => 'dispensed', 'created_at' => now(), 'updated_at' => now()]);
        foreach (['a', 'b', 'c'] as $client) {
            DB::table('trace_scans')->insert(['identifier_id' => $serial, 'product_id' => $s['amox']->id, 'batch_number' => 'MP-AMX-1', 'serial' => 'SN0001',
                'code_format' => 'gs1', 'result' => 'dispensed', 'client_hash' => hash('sha256', $client), 'scanned_at' => now()->subDay()]);
        }

        return compact('s', 'annex');
    }

    /** @return array<string, int> */
    private function businessCounts(): array
    {
        return collect(['purchase_requisitions', 'purchase_orders', 'inventory_movements', 'journal_entries', 'stock_adjustments', 'trace_events', 'regulatory_incidents'])
            ->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
    }
}

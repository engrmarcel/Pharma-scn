<?php

namespace Tests\Feature;

use App\Domain\Billing\Services\EntitlementService;
use App\Domain\FieldForce\Models\FieldVisit;
use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Organizations\Models\OrganizationModule;
use App\Domain\Parties\Models\Customer;
use App\Domain\Sales\Models\SalesOrder;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithInventory;
use Tests\FeatureTestCase;

class FieldForceTest extends FeatureTestCase
{
    use InteractsWithInventory;

    /** Lekki Clinic sits at this point; offsets below are measured from it. */
    private const LAT = 6.4281;

    private const LNG = 3.4219;

    /**
     * Two territories: T1 (rep1, supervisor) with customer A, T2 (rep2) with customer B.
     * Customer C has no territory and no recorded location; it is assigned to rep1 directly.
     */
    private function fieldTeam(): array
    {
        [$owner, $org, $product, $main] = $this->stockedOrganization();
        OrganizationModule::query()->create(['organization_id' => $org->id, 'module' => 'field_force', 'enabled' => true]);
        $this->receive($org, $main, $product, 'FF-1', '200', '+1 year', ['unit_cost' => '50']);
        [$rep1] = $this->organizationMember(['sales_representative'], organization: $org);
        [$rep2] = $this->organizationMember(['sales_representative'], organization: $org);
        [$sup] = $this->organizationMember(['field_supervisor'], organization: $org);

        [$a, $b, $c] = $this->inTenant($org, fn () => [
            Customer::query()->create(['code' => 'A', 'name' => 'Lekki Clinic', 'type' => 'clinic', 'currency_code' => 'NGN', 'latitude' => self::LAT, 'longitude' => self::LNG]),
            Customer::query()->create(['code' => 'B', 'name' => 'Ikeja Pharmacy', 'type' => 'pharmacy', 'currency_code' => 'NGN', 'latitude' => 6.6018, 'longitude' => 3.3515]),
            Customer::query()->create(['code' => 'C', 'name' => 'Yaba Chemist', 'type' => 'pharmacy', 'currency_code' => 'NGN']),
        ]);

        $this->actingAsApi($owner, $org);
        $this->api('POST', 'price-lists', ['code' => 'STD', 'name' => 'Standard', 'is_default' => true,
            'items' => [['product_id' => $product->id, 'package_level_code' => 'pack', 'unit_price' => '3000']]])->assertCreated();
        $t1 = $this->api('POST', 'field-force/territories', ['code' => 'LAG-ISL', 'name' => 'Lagos Island', 'warehouse_id' => $main->id])->assertCreated()->json('data.id');
        $t2 = $this->api('POST', 'field-force/territories', ['code' => 'LAG-MLD', 'name' => 'Lagos Mainland', 'warehouse_id' => $main->id])->assertCreated()->json('data.id');
        $this->api('PUT', "field-force/territories/{$t1}/members", ['members' => [['user_id' => $rep1->id, 'role' => 'representative'], ['user_id' => $sup->id, 'role' => 'supervisor']]])->assertOk();
        $this->api('PUT', "field-force/territories/{$t2}/members", ['members' => [['user_id' => $rep2->id, 'role' => 'representative']]])->assertOk();
        $this->api('PUT', 'field-force/customer-assignments', ['customer_ids' => [$a->id], 'territory_id' => $t1])->assertOk();
        $this->api('PUT', 'field-force/customer-assignments', ['customer_ids' => [$b->id], 'territory_id' => $t2, 'assigned_representative_id' => $rep2->id])->assertOk();
        $this->api('PUT', 'field-force/customer-assignments', ['customer_ids' => [$c->id], 'territory_id' => null, 'assigned_representative_id' => $rep1->id])->assertOk();

        return compact('owner', 'org', 'product', 'main', 'rep1', 'rep2', 'sup', 'a', 'b', 'c', 't1', 't2');
    }

    private function event(array $overrides = []): array
    {
        return $overrides + ['occurred_at' => now()->subMinute()->toIso8601String(), 'location_status' => 'granted',
            'latitude' => self::LAT + 0.0004, 'longitude' => self::LNG, 'accuracy_m' => 15, 'mock_location' => false,
            'device' => ['platform' => 'android', 'app_version' => '1.0.0']];
    }

    private function checkIn(string $customerId, array $event = [], array $extra = [])
    {
        return $this->api('POST', 'field-force/visits', $extra + ['client_reference' => (string) Str::ulid(), 'customer_id' => $customerId,
            'visit_type' => 'detailing', 'check_in' => $this->event($event)]);
    }

    public function test_representatives_see_only_their_customers_and_documents(): void
    {
        $t = $this->fieldTeam();

        $this->actingAsApi($t['rep1'], $t['org']);
        $this->assertEqualsCanonicalizing(['A', 'C'], collect($this->api('GET', 'field-force/customers')->assertOk()->json('data'))->pluck('code')->all());
        $this->assertEqualsCanonicalizing(['A', 'C'], collect($this->api('GET', 'customers')->assertOk()->json('data'))->pluck('code')->all());
        $this->api('GET', "customers/{$t['b']->id}")->assertNotFound();
        $this->api('GET', "field-force/customers/{$t['b']->id}")->assertNotFound();
        $this->api('POST', 'sales-orders', ['customer_id' => $t['b']->id, 'warehouse_id' => $t['main']->id,
            'lines' => [['product_id' => $t['product']->id, 'package_level_code' => 'pack', 'quantity' => '1']]], ['Idempotency-Key' => 'ff-test-key-1'])->assertNotFound();
        $this->api('GET', 'sales/receivables-aging')->assertForbidden();

        $this->actingAsApi($t['rep2'], $t['org']);
        $this->assertSame(['B'], collect($this->api('GET', 'field-force/customers')->json('data'))->pluck('code')->all());

        // The supervisor sees the territory's customers; the owner sees everyone.
        $this->actingAsApi($t['sup'], $t['org']);
        $this->assertSame(['A'], collect($this->api('GET', 'field-force/customers')->json('data'))->pluck('code')->all());
        $this->actingAsApi($t['owner'], $t['org']);
        $this->assertCount(3, $this->api('GET', 'field-force/customers')->json('data'));

        // Reassigning a customer moves its visibility with it.
        $this->api('PUT', 'field-force/customer-assignments', ['customer_ids' => [$t['b']->id], 'territory_id' => $t['t1'], 'assigned_representative_id' => null])->assertOk();
        $this->actingAsApi($t['rep1'], $t['org'])->api('GET', "customers/{$t['b']->id}")->assertOk();
        $this->actingAsApi($t['rep2'], $t['org'])->api('GET', "customers/{$t['b']->id}")->assertNotFound();
    }

    public function test_check_in_location_evidence_is_truthful(): void
    {
        $t = $this->fieldTeam();
        $this->actingAsApi($t['rep1'], $t['org']);
        $cancel = fn ($id) => $this->api('POST', "field-force/visits/{$id}/cancel", ['reason' => 'test'])->assertOk();

        // A precise, real fix about 44 m from the customer: verified.
        $v = $this->checkIn($t['a']->id)->assertCreated()->json('data');
        $this->assertSame('verified', $v['check_in_verification']);
        $this->assertEqualsWithDelta(44, $v['check_in_distance_m'], 2);
        $cancel($v['id']);

        // Permission denied: stored as such, with no coordinates and no verification claim.
        $v = $this->checkIn($t['a']->id, ['location_status' => 'denied', 'latitude' => null, 'longitude' => null, 'accuracy_m' => null])->assertCreated()->json('data');
        $this->assertSame(['denied', 'no_location', null, null], [$v['check_in_location_status'], $v['check_in_verification'], $v['check_in_latitude'], $v['check_in_longitude']]);
        $cancel($v['id']);

        foreach ([
            [['mock_location' => true], 'mock_location'],
            [['accuracy_m' => 500], 'low_accuracy'],
            [['latitude' => self::LAT + 0.05], 'outside_radius'],
        ] as [$event, $expected]) {
            $v = $this->checkIn($t['a']->id, $event)->assertCreated()->json('data');
            $this->assertSame($expected, $v['check_in_verification'], json_encode($event));
            $cancel($v['id']);
        }

        // The customer has no recorded location: a real fix still cannot be verified against it.
        $v = $this->checkIn($t['c']->id)->assertCreated()->json('data');
        $this->assertSame('customer_location_unknown', $v['check_in_verification']);
        $cancel($v['id']);

        // The server refuses contradictory or implausible reports rather than guessing.
        $this->checkIn($t['a']->id, ['latitude' => null, 'longitude' => null])->assertStatus(422)->assertJsonPath('error.code', 'location_missing');
        $this->checkIn($t['a']->id, ['location_status' => 'unavailable'])->assertStatus(422)->assertJsonPath('error.code', 'location_status_inconsistent');
        $this->checkIn($t['a']->id, ['occurred_at' => now()->addHour()->toIso8601String()])->assertStatus(422)->assertJsonPath('error.code', 'device_clock_ahead');
        $this->checkIn($t['a']->id, ['occurred_at' => now()->subDays(60)->toIso8601String()])->assertStatus(422)->assertJsonPath('error.code', 'capture_too_old');
    }

    public function test_visit_lifecycle_replay_ownership_and_follow_up(): void
    {
        $t = $this->fieldTeam();
        $this->actingAsApi($t['rep1'], $t['org']);
        $ref = (string) Str::ulid();

        $visit = $this->checkIn($t['a']->id, [], ['client_reference' => $ref, 'captured_offline' => true])->assertCreated()->json('data');
        // An offline device replays the same check-in: no duplicate.
        $this->checkIn($t['a']->id, [], ['client_reference' => $ref])->assertOk()->assertJsonPath('data.id', $visit['id'])->assertJsonPath('meta.replayed', true);
        // One open visit at a time.
        $this->checkIn($t['c']->id)->assertStatus(409)->assertJsonPath('error.code', 'visit_in_progress');

        $this->api('PATCH', "field-force/visits/{$visit['id']}", ['competitor_information' => 'Rival discount'])->assertForbidden()->assertJsonPath('error.code', 'competitor_information_not_allowed');
        $this->api('POST', "field-force/visits/{$visit['id']}/activities", ['activities' => [['activity_type' => 'detailing', 'product_id' => $t['product']->id, 'notes' => 'New pack size']]])->assertCreated();

        // Another representative cannot see it; the supervisor can see it but not change it.
        $this->actingAsApi($t['rep2'], $t['org'])->api('GET', "field-force/visits/{$visit['id']}")->assertNotFound();
        $this->actingAsApi($t['sup'], $t['org'])->api('GET', "field-force/visits/{$visit['id']}")->assertOk()->assertJsonCount(1, 'data.activities');
        $this->api('POST', "field-force/visits/{$visit['id']}/check-out", ['check_out' => $this->event(), 'outcome' => 'successful'])
            ->assertForbidden()->assertJsonPath('error.code', 'visit_not_yours');

        $this->actingAsApi($t['rep1'], $t['org']);
        $this->api('POST', "field-force/visits/{$visit['id']}/check-out", ['check_out' => $this->event(['occurred_at' => now()->subMinutes(5)->toIso8601String()]), 'outcome' => 'successful'])
            ->assertStatus(422)->assertJsonPath('error.code', 'check_out_before_check_in');
        $done = ['check_out' => $this->event(['occurred_at' => now()->toIso8601String()]), 'outcome' => 'successful', 'discussion_notes' => 'Interested in the 20-pack',
            'order_opportunity' => true, 'next_action' => 'Send price list', 'follow_up_date' => today()->addDays(3)->toDateString(),
            'products_discussed' => [$t['product']->id], 'activities' => [['activity_type' => 'order_request']]];
        $this->api('POST', "field-force/visits/{$visit['id']}/check-out", $done)->assertOk()
            ->assertJsonPath('data.status', 'completed')->assertJsonPath('data.check_out_verification', 'verified')->assertJsonCount(2, 'data.activities');
        // Replayed check-out returns the completed visit unchanged.
        $this->api('POST', "field-force/visits/{$visit['id']}/check-out", $done)->assertOk()->assertJsonCount(2, 'data.activities');

        $this->api('GET', 'field-force/follow-ups', ['status' => 'open'])->assertOk()->assertJsonPath('data.0.title', 'Send price list');
    }

    public function test_mobile_orders_are_requests_until_the_erp_accepts_them(): void
    {
        $t = $this->fieldTeam();
        $this->actingAsApi($t['rep1'], $t['org']);
        $ref = (string) Str::ulid();
        $payload = ['client_reference' => $ref, 'customer_id' => $t['a']->id, 'captured_at' => now()->subMinutes(10)->toIso8601String(), 'captured_offline' => true,
            'lines' => [['product_id' => $t['product']->id, 'package_level_code' => 'pack', 'quantity' => '2', 'indicative_unit_price' => '2500']]];

        $draft = $this->api('POST', 'field-force/orders', $payload)->assertCreated()->json('data');
        $this->assertSame(['draft', false, false, null], [$draft['status'], $draft['accepted_by_erp'], $draft['stock_reserved'], $draft['sales_order']]);
        $this->assertSame(0, $this->inTenant($t['org'], fn () => StockReservation::query()->count()), 'a captured order reserves nothing');
        $this->api('POST', 'field-force/orders', $payload)->assertOk()->assertJsonPath('data.id', $draft['id']);

        $accepted = $this->api('POST', "field-force/orders/{$draft['id']}/submit")->assertOk()->json('data');
        $this->assertSame(['accepted', true, true, 'confirmed'], [$accepted['status'], $accepted['accepted_by_erp'], $accepted['stock_reserved'], $accepted['sales_order']['status']]);
        $this->assertSame('6000.0000', $accepted['sales_order']['total'], 'the ERP prices the order, not the device');
        $this->assertSame(1, $this->inTenant($t['org'], fn () => StockReservation::query()->where('status', 'active')->count()));
        $this->api('POST', "field-force/orders/{$draft['id']}/cancel", ['reason' => 'x'])->assertStatus(409);

        // More than is in stock: rejected with the reason, and nothing is left behind in the ERP.
        $big = $this->api('POST', 'field-force/orders', ['client_reference' => (string) Str::ulid(), 'submit' => true] + array_merge($payload, ['client_reference' => (string) Str::ulid(),
            'lines' => [['product_id' => $t['product']->id, 'package_level_code' => 'pack', 'quantity' => '500']]]))->assertCreated()->json('data');
        $this->assertSame('rejected', $big['status']);
        $this->assertSame('insufficient_stock', $big['rejection_reasons'][0]['code']);
        $this->assertSame(1, $this->inTenant($t['org'], fn () => SalesOrder::query()->count()));

        // Fix and resubmit.
        $this->api('PATCH', "field-force/orders/{$big['id']}", ['lines' => [['product_id' => $t['product']->id, 'package_level_code' => 'pack', 'quantity' => '1']]])
            ->assertOk()->assertJsonPath('data.status', 'draft');
        $this->api('POST', "field-force/orders/{$big['id']}/submit")->assertOk()->assertJsonPath('data.status', 'accepted');

        // Customers outside the representative's assignments are not reachable.
        $this->api('POST', 'field-force/orders', array_merge($payload, ['client_reference' => (string) Str::ulid(), 'customer_id' => $t['b']->id]))->assertNotFound();
        $this->actingAsApi($t['rep2'], $t['org'])->api('GET', "field-force/orders/{$draft['id']}")->assertNotFound();
    }

    public function test_catalogue_shows_customer_prices_and_a_stamped_stock_snapshot(): void
    {
        $t = $this->fieldTeam();
        $res = $this->actingAsApi($t['rep1'], $t['org'])->api('GET', 'field-force/catalogue', ['customer_id' => $t['a']->id])->assertOk();

        $item = $res->json('data.0');
        $this->assertSame('200.0000', $item['sellable_base_units']);
        $pack = collect($item['package_levels'])->firstWhere('code', 'pack');
        $this->assertSame(['3000', 'default_price_list'], [rtrim(rtrim($pack['indicative_unit_price'], '0'), '.'), $pack['price_source']]);
        $this->assertNotNull($res->json('meta.stock_as_of'));
        $this->assertSame($t['main']->id, $res->json('meta.warehouse_id'), "the territory's fulfilment warehouse");

        $this->api('GET', 'field-force/catalogue', ['customer_id' => $t['b']->id])->assertNotFound();
    }

    public function test_supervisor_reports_and_follow_up_assignment_respect_the_team(): void
    {
        $t = $this->fieldTeam();
        foreach (['rep1' => 'a', 'rep2' => 'b'] as $rep => $customer) {
            $this->actingAsApi($t[$rep], $t['org']);
            $id = $this->checkIn($t[$customer]->id)->assertCreated()->json('data.id');
            $this->api('POST', "field-force/visits/{$id}/check-out", ['check_out' => $this->event(['occurred_at' => now()->toIso8601String()]), 'outcome' => 'successful'])->assertOk();
        }

        $this->actingAsApi($t['rep1'], $t['org']);
        $this->api('POST', 'field-force/follow-ups', ['customer_id' => $t['a']->id, 'assigned_to' => $t['rep2']->id, 'title' => 'x', 'due_on' => today()->toDateString()])
            ->assertForbidden()->assertJsonPath('error.code', 'assignee_not_allowed');
        $mine = collect($this->api('GET', 'field-force/reports/activity')->assertOk()->json('data.representatives'));
        $this->assertSame([$t['rep1']->id], $mine->pluck('representative_id')->all());

        $this->actingAsApi($t['sup'], $t['org']);
        $fu = $this->api('POST', 'field-force/follow-ups', ['customer_id' => $t['a']->id, 'assigned_to' => $t['rep1']->id, 'title' => 'Collect licence copy', 'due_on' => today()->toDateString()])
            ->assertCreated()->json('data.id');
        $team = collect($this->api('GET', 'field-force/reports/activity')->assertOk()->json('data.representatives'))->keyBy('representative_id');
        $this->assertSame([$t['rep1']->id], $team->keys()->all(), 'rep2 is not in a supervised territory');
        $this->assertSame([1, 1, 1], [$team[$t['rep1']->id]['visits'], $team[$t['rep1']->id]['verified_check_ins'], $team[$t['rep1']->id]['follow_ups_due']]);

        $this->actingAsApi($t['rep1'], $t['org'])->api('POST', "field-force/follow-ups/{$fu}/complete", ['outcome' => 'Received'])->assertOk()->assertJsonPath('data.status', 'done');

        $this->actingAsApi($t['owner'], $t['org']);
        $all = collect($this->api('GET', 'field-force/reports/activity')->json('data.representatives'));
        $this->assertCount(2, $all);
        $this->assertSame(100.0, (float) $all->firstWhere('representative_id', $t['rep1']->id)['follow_up_compliance_percent']);
    }

    public function test_mobile_user_limit_module_gate_and_location_retention(): void
    {
        $t = $this->fieldTeam();
        app(EntitlementService::class)->setOverride($t['org'], 'mobile_users', true, 3, 'test', $t['owner']);
        [$extra] = $this->organizationMember(['sales_representative'], organization: $t['org']);
        $this->actingAsApi($t['owner'], $t['org'])->api('PUT', "field-force/territories/{$t['t2']}/members", ['members' => [
            ['user_id' => $t['rep2']->id, 'role' => 'representative'], ['user_id' => $extra->id, 'role' => 'representative'],
        ]])->assertForbidden()->assertJsonPath('error.code', 'entitlement_limit_reached');
        // Removing members never needs a free slot.
        $this->api('PUT', "field-force/territories/{$t['t2']}/members", ['members' => []])->assertOk();

        // Location retention: raw coordinates go, the verification result stays.
        $id = $this->actingAsApi($t['rep1'], $t['org'])->checkIn($t['a']->id)->assertCreated()->json('data.id');
        FieldVisit::acrossTenants()->whereKey($id)->update(['check_in_at' => now()->subDays(400)]);
        $this->artisan('field-force:purge-locations')->assertSuccessful();
        $visit = FieldVisit::acrossTenants()->find($id);
        $this->assertSame([null, null, 'verified'], [$visit->check_in_latitude, $visit->check_in_longitude, $visit->check_in_verification]);
        $this->assertNotNull($visit->location_purged_at);

        // Without the module the endpoints are closed.
        OrganizationModule::query()->where('organization_id', $t['org']->id)->where('module', 'field_force')->update(['enabled' => false]);
        $this->api('GET', 'field-force/me')->assertForbidden()->assertJsonPath('error.code', 'module_not_enabled');
    }
}

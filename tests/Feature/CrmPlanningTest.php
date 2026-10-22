<?php

namespace Tests\Feature;

use App\Domain\Crm\Models\VisitPlanItem;
use App\Domain\FieldForce\Models\FieldFollowUp;
use App\Domain\Parties\Models\Customer;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithFieldForce;
use Tests\FeatureTestCase;

/**
 * Phase 6b: visit plans and approvals, route order, targets, opportunities and
 * their conversion, follow-up automation and handover, all within territory permissions.
 */
class CrmPlanningTest extends FeatureTestCase
{
    use InteractsWithFieldForce;

    public function test_visit_plans_are_approved_only_by_supervisors_who_can_see_every_planned_customer(): void
    {
        $t = $this->fieldTeam();
        $today = today()->toDateString();
        $tomorrow = today()->addDay()->toDateString();
        $this->actingAsApi($t['rep1'], $t['org']);
        $plan = $this->api('POST', 'crm/visit-plans', ['period_start' => $today, 'period_end' => today()->addDays(6)->toDateString(), 'items' => [
            ['customer_id' => $t['a']->id, 'planned_date' => $today, 'objective' => 'Detail Testmox'],
            ['customer_id' => $t['c']->id, 'planned_date' => $tomorrow],
        ]])->assertCreated()->json('data');
        $this->assertSame(['draft', 2, 0], [$plan['status'], count($plan['items']), $plan['hidden_items']]);
        $this->api('POST', 'crm/visit-plans', ['period_start' => $tomorrow, 'period_end' => $tomorrow, 'items' => []])->assertStatus(409)->assertJsonPath('error.code', 'visit_plan_overlap');
        $this->api('PATCH', "crm/visit-plans/{$plan['id']}", ['items' => [['customer_id' => $t['b']->id, 'planned_date' => $today]]])->assertNotFound();
        $this->api('POST', 'crm/visit-plans', ['period_start' => today()->addDays(40)->toDateString(), 'period_end' => today()->addDays(80)->toDateString(), 'items' => []])->assertStatus(422);
        $this->api('POST', "crm/visit-plans/{$plan['id']}/submit")->assertOk()->assertJsonPath('data.status', 'submitted');
        $this->api('POST', "crm/visit-plans/{$plan['id']}/approve")->assertForbidden();

        // The Lagos Island supervisor cannot see customer C, so cannot decide this plan, and does not see that call.
        $this->actingAsApi($t['sup'], $t['org']);
        $this->assertSame(1, $this->api('GET', "crm/visit-plans/{$plan['id']}")->assertOk()->json('data.hidden_items'));
        $this->api('POST', "crm/visit-plans/{$plan['id']}/approve")->assertStatus(403)->assertJsonPath('error.code', 'visit_plan_outside_scope');
        $this->actingAsApi($t['owner'], $t['org'])->api('POST', "crm/visit-plans/{$plan['id']}/reject")->assertStatus(422);
        $this->api('POST', "crm/visit-plans/{$plan['id']}/approve", ['notes' => 'Good plan'])->assertOk()->assertJsonPath('data.status', 'approved');

        // Checking in at the planned customer fulfils the planned call.
        $this->checkIn($t['rep1'], $t, $t['a']->id);
        $item = VisitPlanItem::query()->withoutGlobalScopes()->where('customer_id', $t['a']->id)->firstOrFail();
        $this->assertSame('visited', $item->status);
        $this->assertNotNull($item->field_visit_id);
        $adherence = $this->actingAsApi($t['rep1'], $t['org'])->api('GET', "crm/visit-plans/{$plan['id']}")->json('data.adherence');
        $this->assertEquals(['visited' => 1, 'pending' => 1, 'missed' => 0, 'adherence_percent' => 100], array_intersect_key($adherence, ['visited' => 0, 'pending' => 0, 'missed' => 0, 'adherence_percent' => 0]));

        // Another representative's plan is invisible to this supervisor.
        $other = $this->actingAsApi($t['rep2'], $t['org'])->api('POST', 'crm/visit-plans', ['period_start' => $today, 'period_end' => $today,
            'items' => [['customer_id' => $t['b']->id, 'planned_date' => $today]]])->assertCreated()->json('data.id');
        $this->actingAsApi($t['sup'], $t['org'])->api('GET', "crm/visit-plans/{$other}")->assertNotFound();
    }

    public function test_route_order_follows_nearest_neighbour_and_puts_unlocated_customers_last(): void
    {
        $t = $this->fieldTeam();
        [$d, $e] = $this->inTenant($t['org'], fn () => [
            Customer::query()->create(['code' => 'D', 'name' => 'Far Clinic', 'type' => 'clinic', 'currency_code' => 'NGN', 'latitude' => 6.60, 'longitude' => 3.35, 'territory_id' => $t['t1']]),
            Customer::query()->create(['code' => 'E', 'name' => 'Near Clinic', 'type' => 'clinic', 'currency_code' => 'NGN', 'latitude' => 6.44, 'longitude' => 3.43, 'territory_id' => $t['t1']]),
        ]);
        $date = today()->addWeek()->toDateString();
        $plan = $this->actingAsApi($t['rep1'], $t['org'])->api('POST', 'crm/visit-plans', ['period_start' => $date, 'period_end' => $date, 'items' => [
            ['customer_id' => $d->id, 'planned_date' => $date], ['customer_id' => $t['c']->id, 'planned_date' => $date],
            ['customer_id' => $e->id, 'planned_date' => $date], ['customer_id' => $t['a']->id, 'planned_date' => $date],
        ]])->json('data.id');

        $route = $this->api('POST', "crm/visit-plans/{$plan}/route", ['date' => $date, 'start_latitude' => self::LAT, 'start_longitude' => self::LNG])->assertOk()->json('data');
        $this->assertSame([$t['a']->id, $e->id, $d->id, $t['c']->id], array_column($route['stops'], 'customer_id'));
        $this->assertFalse($route['stops'][3]['located']);
        $this->assertGreaterThan(15, $route['total_distance_km']);
        $this->assertSame([1, 2, 3, 4], VisitPlanItem::query()->withoutGlobalScopes()->where('visit_plan_id', $plan)
            ->orderBy('sequence')->pluck('sequence')->all());
    }

    public function test_target_achievement_never_reaches_beyond_the_viewers_customers(): void
    {
        $t = $this->fieldTeam();
        $period = ['period_start' => today()->startOfMonth()->toDateString(), 'period_end' => today()->endOfMonth()->toDateString()];
        $this->actingAsApi($t['owner'], $t['org']);
        $repTarget = $this->api('POST', 'crm/targets', ['scope_type' => 'representative', 'representative_id' => $t['rep1']->id, 'metric' => 'visits_completed', 'target_value' => '10'] + $period)
            ->assertCreated()->json('data.id');
        $valueTarget = $this->api('POST', 'crm/targets', ['scope_type' => 'representative', 'representative_id' => $t['rep1']->id, 'metric' => 'field_order_value', 'target_value' => '12000'] + $period)->json('data.id');
        $territoryTarget = $this->api('POST', 'crm/targets', ['scope_type' => 'territory', 'territory_id' => $t['t1'], 'metric' => 'visits_completed', 'target_value' => '4'] + $period)->json('data.id');
        $this->api('POST', 'crm/targets', ['scope_type' => 'representative', 'representative_id' => $t['rep1']->id, 'metric' => 'visits_completed', 'target_value' => '5'] + $period)
            ->assertStatus(409);
        $this->api('POST', 'crm/targets', ['scope_type' => 'territory', 'territory_id' => $t['t1'], 'metric' => 'product_units', 'target_value' => '5'] + $period)->assertStatus(422);

        $this->checkIn($t['rep1'], $t, $t['a']->id, checkOut: true);
        $this->checkIn($t['rep1'], $t, $t['a']->id, checkOut: true);
        $this->checkIn($t['rep1'], $t, $t['c']->id, checkOut: true);
        $this->acceptedOrder($t['rep1'], $t, $t['c']->id);

        // The representative sees the whole of their own target.
        $own = collect($this->actingAsApi($t['rep1'], $t['org'])->api('GET', 'crm/targets')->assertOk()->json('data'))->keyBy('id');
        $this->assertSame(['3.0000', 'full'], [$own[$repTarget]['achievement']['actual'], $own[$repTarget]['achievement']['scope']]);
        $this->assertSame('6000.0000', $own[$valueTarget]['achievement']['actual']);
        $this->assertEquals(30, $own[$repTarget]['achievement']['attainment_percent']);

        // The supervisor sees rep1's figures only for customers in Lagos Island; the territory target in full.
        $this->actingAsApi($t['sup'], $t['org']);
        $this->assertSame(['2.0000', 'partial'], [$this->api('GET', "crm/targets/{$repTarget}")->json('data.achievement.actual'), $this->api('GET', "crm/targets/{$repTarget}")->json('data.achievement.scope')]);
        $this->assertSame('0.0000', $this->api('GET', "crm/targets/{$valueTarget}")->json('data.achievement.actual'), 'the order was for a customer outside the supervisor\'s territory');
        $this->assertSame('2.0000', $this->api('GET', "crm/targets/{$territoryTarget}")->json('data.achievement.actual'));

        // Another representative sees none of it.
        $this->actingAsApi($t['rep2'], $t['org']);
        $this->assertSame([], $this->api('GET', 'crm/targets')->json('data'));
        $this->api('GET', "crm/targets/{$repTarget}")->assertNotFound();
        $this->api('POST', 'crm/targets', ['scope_type' => 'representative', 'representative_id' => $t['rep2']->id, 'metric' => 'visits_completed', 'target_value' => '1'] + $period)->assertForbidden();
    }

    public function test_leads_convert_to_customers_and_opportunities_are_won_only_with_their_order(): void
    {
        $t = $this->fieldTeam();
        $this->actingAsApi($t['rep1'], $t['org']);
        $lead = $this->api('POST', 'crm/opportunities', ['title' => 'New hospital pharmacy', 'lead_name' => 'Victoria Island Hospital', 'lead_contact_name' => 'Pharm. Bola Ade',
            'lead_phone' => '+2348000000001', 'lead_city' => 'Lagos', 'territory_id' => $t['t1'], 'expected_value' => '500000'])->assertCreated()->json('data');
        $this->assertSame(['lead', 10], [$lead['stage'], $lead['probability']]);
        $this->api('POST', 'crm/opportunities', ['title' => 'Nothing'])->assertStatus(422)->assertJsonPath('error.code', 'lead_name_required');
        $this->api('PATCH', "crm/opportunities/{$lead['id']}", ['stage' => 'won', 'field_order_id' => (string) Str::ulid()])->assertStatus(422)->assertJsonPath('error.code', 'opportunity_needs_customer');

        // The supervisor of the lead's territory sees it; another representative does not.
        $this->assertCount(1, $this->actingAsApi($t['sup'], $t['org'])->api('GET', 'crm/opportunities')->json('data'));
        $this->assertCount(0, $this->actingAsApi($t['rep2'], $t['org'])->api('GET', 'crm/opportunities')->json('data'));

        $this->actingAsApi($t['rep1'], $t['org'])->api('POST', "crm/opportunities/{$lead['id']}/convert-customer", ['code' => 'VIH', 'type' => 'hospital'])->assertForbidden();
        $converted = $this->actingAsApi($t['owner'], $t['org'])->api('POST', "crm/opportunities/{$lead['id']}/convert-customer", ['code' => 'VIH', 'type' => 'hospital'])->assertOk()->json('data');
        $this->assertSame(['qualified', null, null], [$converted['stage'], $converted['lead_phone'], $converted['lead_contact_name']]);
        $customer = $this->inTenant($t['org'], fn () => Customer::query()->findOrFail($converted['customer_id']));
        $this->assertSame([$t['t1'], $t['rep1']->id], [$customer->territory_id, $customer->assigned_representative_id]);
        $this->actingAsApi($t['rep1'], $t['org'])->api('GET', "crm/customers/{$customer->id}/contacts")->assertOk()
            ->assertJsonPath('data.0.name', 'Pharm. Bola Ade')->assertJsonPath('data.0.contact_consent', 'unknown');

        $this->api('PATCH', "crm/opportunities/{$lead['id']}", ['stage' => 'proposal', 'next_step' => 'Send quotation'])->assertOk()->assertJsonPath('data.probability', 50);
        $this->api('PATCH', "crm/opportunities/{$lead['id']}", ['stage' => 'won'])->assertStatus(422)->assertJsonPath('error.code', 'opportunity_order_required');
        $orderId = $this->acceptedOrder($t['rep1'], $t, $customer->id);
        $won = $this->api('PATCH', "crm/opportunities/{$lead['id']}", ['stage' => 'won', 'field_order_id' => $orderId])->assertOk()->json('data');
        $this->assertSame(['won', 100], [$won['stage'], $won['probability']]);
        $this->assertNotNull($won['sales_order_id']);
        $this->api('PATCH', "crm/opportunities/{$lead['id']}", ['stage' => 'lost'])->assertStatus(409);

        $second = $this->api('POST', 'crm/opportunities', ['title' => 'Antibiotics range', 'customer_id' => $t['a']->id, 'expected_value' => '100000'])->json('data.id');
        $this->api('PATCH', "crm/opportunities/{$second}", ['stage' => 'lost'])->assertStatus(422);
        $this->api('PATCH', "crm/opportunities/{$second}", ['stage' => 'lost', 'lost_reason' => 'Chose a competitor'])->assertOk();
        $pipeline = $this->api('GET', 'crm/opportunities')->json('pipeline');
        $this->assertSame([1, '500000.0000', 1], [$pipeline['won']['count'], $pipeline['won']['value'], $pipeline['lost']['count']]);
    }

    public function test_follow_up_automation_creates_each_follow_up_once_and_escalates_to_the_territory_supervisor(): void
    {
        $t = $this->fieldTeam();
        $this->actingAsApi($t['owner'], $t['org']);
        $this->api('PUT', 'field-force/customer-assignments', ['customer_ids' => [$t['a']->id], 'territory_id' => $t['t1'], 'assigned_representative_id' => $t['rep1']->id])->assertOk();
        $this->api('PATCH', "crm/customers/{$t['a']->id}/profile", ['visit_frequency_days' => 7])->assertOk();

        // A planned call whose day passes, and an opportunity that goes quiet.
        $this->actingAsApi($t['rep1'], $t['org']);
        $plan = $this->api('POST', 'crm/visit-plans', ['period_start' => today()->toDateString(), 'period_end' => today()->addDay()->toDateString(),
            'items' => [['customer_id' => $t['c']->id, 'planned_date' => today()->addDay()->toDateString()]]])->json('data.id');
        $this->api('POST', "crm/visit-plans/{$plan}/submit")->assertOk();
        $this->api('POST', 'crm/opportunities', ['title' => 'Quiet one', 'customer_id' => $t['a']->id])->assertCreated();
        $this->api('POST', 'field-force/follow-ups', ['customer_id' => $t['a']->id, 'title' => 'Bring samples', 'due_on' => today()->addDay()->toDateString()])->assertCreated();

        // A visit where the customer was unavailable gets its follow-up immediately.
        $visit = $this->checkIn($t['rep1'], $t, $t['c']->id);
        $this->api('POST', "field-force/visits/{$visit}/check-out", ['check_out' => $this->event(['occurred_at' => now()->toIso8601String()]), 'outcome' => 'customer_unavailable'])->assertOk();
        $this->assertSame('automation', FieldFollowUp::query()->withoutGlobalScopes()->where('automation_key', "visit_outcome:{$visit}")->value('source'));

        $this->travel(16)->days();
        $this->actingAsApi($t['rep1'], $t['org'])->api('POST', 'crm/automation/run')->assertForbidden();
        $first = $this->actingAsApi($t['owner'], $t['org'])->api('POST', 'crm/automation/run')->assertOk()->json('data');
        $this->assertSame(['missed_plan_items' => 1, 'overdue_visits' => 1, 'stale_opportunities' => 1], array_intersect_key($first, ['missed_plan_items' => 0, 'overdue_visits' => 0, 'stale_opportunities' => 0]));
        // Only the Lagos Island follow-up escalates: customer C has no territory, so no supervisor.
        $this->assertSame(1, $first['escalated']);
        $second = $this->api('POST', 'crm/automation/run')->json('data');
        $this->assertSame(['missed_plan_items' => 0, 'overdue_visits' => 0, 'stale_opportunities' => 0, 'escalated' => 0], $second);

        // Escalations go to the supervisor of the customer's territory, and only for customers in a supervised territory.
        $escalations = FieldFollowUp::query()->withoutGlobalScopes()->where('automation_key', 'like', 'escalation:%')->get();
        $this->assertTrue($escalations->every(fn ($f) => $f->assigned_to === $t['sup']->id));
        $this->assertSame([$t['a']->id], $escalations->pluck('customer_id')->unique()->values()->all());

        $this->api('PATCH', 'crm/automation/settings', ['overdue_visits' => false, 'escalation_days' => null])->assertOk()->assertJsonPath('data.overdue_visits', false);
        $this->travel(8)->days();
        $this->assertSame(0, $this->api('POST', 'crm/automation/run')->json('data.overdue_visits'));
    }

    public function test_handover_moves_accounts_open_work_and_future_calls(): void
    {
        $t = $this->fieldTeam();
        $this->actingAsApi($t['rep1'], $t['org']);
        $plan = $this->api('POST', 'crm/visit-plans', ['period_start' => today()->addDay()->toDateString(), 'period_end' => today()->addDays(2)->toDateString(),
            'items' => [['customer_id' => $t['c']->id, 'planned_date' => today()->addDay()->toDateString()]]])->json('data.id');
        $this->api('POST', 'field-force/follow-ups', ['customer_id' => $t['c']->id, 'title' => 'Call back', 'due_on' => today()->addDays(3)->toDateString()])->assertCreated();
        $this->api('POST', 'crm/opportunities', ['title' => 'Range extension', 'customer_id' => $t['c']->id])->assertCreated();

        $this->api('POST', 'crm/handover', ['from_user_id' => $t['rep1']->id, 'to_user_id' => $t['rep2']->id])->assertForbidden();
        $counts = $this->actingAsApi($t['owner'], $t['org'])->api('POST', 'crm/handover', ['from_user_id' => $t['rep1']->id, 'to_user_id' => $t['rep2']->id])->assertOk()->json('data');
        $this->assertSame(['customers' => 1, 'follow_ups' => 1, 'opportunities' => 1, 'planned_visits_cancelled' => 1], $counts);

        $this->assertSame([$t['b']->id, $t['c']->id], collect($this->actingAsApi($t['rep2'], $t['org'])->api('GET', 'crm/customers')->json('data'))->sortBy('code')->pluck('id')->values()->all());
        $this->assertCount(1, $this->api('GET', 'crm/opportunities')->json('data'));
        $this->assertSame('cancelled', $this->actingAsApi($t['rep1'], $t['org'])->api('GET', "crm/visit-plans/{$plan}")->json('data.items.0.status'));
    }

    private function checkIn(User $rep, array $t, string $customerId, bool $checkOut = false): string
    {
        $this->actingAsApi($rep, $t['org']);
        $id = $this->api('POST', 'field-force/visits', ['client_reference' => (string) Str::ulid(), 'customer_id' => $customerId, 'visit_type' => 'routine',
            'check_in' => $this->event()])->assertCreated()->json('data.id');
        if ($checkOut) {
            $this->api('POST', "field-force/visits/{$id}/check-out", ['check_out' => $this->event(['occurred_at' => now()->toIso8601String()]), 'outcome' => 'successful'])->assertOk();
        }

        return $id;
    }

    private function acceptedOrder(User $rep, array $t, string $customerId): string
    {
        $this->actingAsApi($rep, $t['org']);
        $id = $this->api('POST', 'field-force/orders', ['client_reference' => (string) Str::ulid(), 'customer_id' => $customerId, 'captured_at' => now()->subMinute()->toIso8601String(), 'warehouse_id' => $t['main']->id,
            'lines' => [['product_id' => $t['product']->id, 'package_level_code' => 'pack', 'quantity' => '2']]])->assertCreated()->json('data.id');
        $this->api('POST', "field-force/orders/{$id}/submit")->assertOk()->assertJsonPath('data.status', 'accepted');

        return $id;
    }
}

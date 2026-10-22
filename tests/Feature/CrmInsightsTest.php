<?php

namespace Tests\Feature;

use App\Domain\Crm\Models\DetailingRecord;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Livewire\FieldForce\SupervisorDashboard;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithFieldForce;
use Tests\FeatureTestCase;

/**
 * Phase 6c: supervisor dashboard, CRM analytics, the web dashboard and the
 * mobile workflows (my day, offline detailing, feedback and interactions).
 * Phase 6 acceptance: supervisors review authorized field activities, visits,
 * orders and sales outcomes without violating tenant or territory permissions.
 */
class CrmInsightsTest extends FeatureTestCase
{
    use InteractsWithFieldForce;

    public function test_supervisor_dashboard_shows_the_team_within_the_supervisors_territories(): void
    {
        $t = $this->teamAcrossTerritories();
        $this->visit($t['rep1'], $t, $t['a']->id, checkOut: false);  // in progress, Lagos Island
        $this->actingAsApi($t['rep1'], $t['org']);
        $this->api('POST', 'field-force/orders', $this->order($t, $t['b']->id))->assertCreated(); // Mainland customer
        $this->visit($t['rep2'], $t, $t['b']->id);

        // rep1 plans two periods: one entirely in Lagos Island, one that includes customer C.
        $this->actingAsApi($t['rep1'], $t['org']);
        $island = $this->plan([[$t['a']->id, today()->addWeek()]]);
        $mixed = $this->plan([[$t['a']->id, today()->addWeeks(3)], [$t['c']->id, today()->addWeeks(3)]]);

        $board = $this->actingAsApi($t['sup'], $t['org'])->api('GET', 'crm/dashboard/supervisor')->assertOk()->json('data');
        $this->assertSame([1, 1], [$board['totals']['visits_today'], $board['totals']['in_progress']]);
        $this->assertSame(['Lekki Clinic'], array_column($board['in_progress'], 'customer'));
        $team = collect($board['team'])->keyBy('representative_id');
        $this->assertFalse($team->has($t['rep2']->id), 'rep2 is not in the supervisor\'s team');
        $this->assertSame(0, $team[$t['rep1']->id]['orders_7d'], 'the Mainland order is outside the supervisor\'s reach');
        $this->assertEquals([$island => true, $mixed => false], collect($board['pending_approvals'])->pluck('decidable', 'visit_plan_id')->all());

        $this->assertSame(2, $this->actingAsApi($t['owner'], $t['org'])->api('GET', 'crm/dashboard/supervisor')->json('data.totals.visits_today'), 'the owner sees every visit');
        $this->actingAsApi($t['rep2'], $t['org'])->api('GET', 'crm/dashboard/supervisor')->assertForbidden();
        [$outsider, $otherOrg] = $this->organizationMember();
        $this->actingAsApi($outsider, $otherOrg)->api('GET', 'crm/dashboard/supervisor')->assertForbidden();
    }

    public function test_crm_analytics_measure_coverage_conversion_pipeline_and_detailing_for_the_viewers_customers(): void
    {
        $t = $this->teamAcrossTerritories();
        $this->actingAsApi($t['owner'], $t['org']);
        $this->api('PATCH', "crm/customers/{$t['a']->id}/profile", ['tier' => 'A', 'visit_frequency_days' => 30])->assertOk();
        $this->api('PATCH', "crm/customers/{$t['c']->id}/profile", ['tier' => 'B', 'visit_frequency_days' => 7])->assertOk();
        $message = $this->approvedMessage($t);

        $visit = $this->visit($t['rep1'], $t, $t['a']->id, checkOut: false);
        $this->api('POST', "field-force/visits/{$visit}/detailing", ['product_id' => $t['product']->id, 'message_ids' => [$message], 'reception' => 'positive'])->assertCreated();
        $this->api('POST', "field-force/visits/{$visit}/check-out", ['check_out' => $this->event(['occurred_at' => now()->toIso8601String()]), 'outcome' => 'successful', 'order_opportunity' => true])->assertOk();
        $order = $this->api('POST', 'field-force/orders', $this->order($t, $t['a']->id))->json('data.id');
        $this->api('POST', "field-force/orders/{$order}/submit")->assertOk()->assertJsonPath('data.status', 'accepted');
        $this->visit($t['rep1'], $t, $t['b']->id);

        $rep = $this->actingAsApi($t['rep1'], $t['org'])->api('GET', 'crm/analytics')->assertOk()->json('data');
        $this->assertSame([3, 2], [$rep['coverage']['customers'], $rep['coverage']['visited']]);
        $this->assertEquals(50, $rep['coverage']['frequency_compliance_percent'], 'A is within 30 days; C was never visited');
        $this->assertSame(['orders_accepted' => 1, 'accepted_value' => '6000.0000'], array_intersect_key($rep['conversion'], ['orders_accepted' => 0, 'accepted_value' => 0]));
        $this->assertEquals(50, $rep['conversion']['visited_customers_ordering_percent']);
        $this->assertSame([1, 1], [$rep['detailing'][0]['customers_reached'], $rep['detailing'][0]['records']]);

        // The supervisor's analytics cover Lagos Island only.
        $sup = $this->actingAsApi($t['sup'], $t['org'])->api('GET', 'crm/analytics')->json('data');
        $this->assertSame([1, 1, 1], [$sup['coverage']['customers'], $sup['coverage']['visited'], $sup['visits']['completed']]);
        $this->assertSame(['A' => ['customers' => 1, 'visited' => 1, 'visits' => 1]], $sup['coverage']['by_tier']);
        $this->actingAsApi($t['sup'], $t['org'])->api('GET', 'crm/analytics?territory_id='.$t['t2'])->assertOk()->assertJsonPath('data.coverage.customers', 0);
    }

    public function test_detailing_feedback_and_interactions_captured_offline_sync_through_the_inbox(): void
    {
        $t = $this->fieldTeam();
        $message = $this->approvedMessage($t);
        $token = $this->actingAsApi($t['rep1'], $t['org'])->api('POST', 'devices', ['name' => 'Rep phone', 'platform' => 'android', 'role' => 'field'])->assertCreated()->json('data.token');

        $visitRef = (string) Str::ulid();
        $feedbackRef = (string) Str::ulid();
        $batch = ['envelopes' => [
            $this->envelope('field.visit.check_in', ['client_reference' => $visitRef, 'customer_id' => $t['a']->id, 'visit_type' => 'detailing', 'check_in' => $this->event()]),
            $this->envelope('crm.detailing.record', ['client_reference' => (string) Str::ulid(), 'visit_client_reference' => $visitRef, 'product_id' => $t['product']->id,
                'message_ids' => [$message], 'samples_quantity' => '3', 'sample_batch_number' => 'ff-1', 'reception' => 'neutral']),
            $this->envelope('crm.feedback.record', ['client_reference' => $feedbackRef, 'visit_client_reference' => $visitRef, 'customer_id' => $t['a']->id,
                'product_id' => $t['product']->id, 'category' => 'product_quality', 'description' => 'Rash reported after use', 'possible_adverse_event' => true]),
            $this->envelope('crm.interaction.log', ['client_reference' => (string) Str::ulid(), 'customer_id' => $t['a']->id, 'channel' => 'call', 'direction' => 'outbound',
                'subject' => 'Called ahead', 'occurred_at' => now()->subHour()->toIso8601String()]),
            $this->envelope('crm.detailing.record', ['visit_client_reference' => $visitRef, 'product_id' => $t['product']->id, 'message_ids' => [(string) Str::ulid()]]),
        ]];

        $acks = $this->asDevice($token, $t['org'], $batch)->assertOk()->json('data');
        $this->assertSame(['synced', 'synced', 'synced', 'synced', 'rejected'], array_column($acks, 'status'));
        $this->assertStringContainsString('pharmacovigilance', $acks[2]['result']['pharmacovigilance_notice']);
        $this->assertSame('detailing_message_not_approved', $acks[4]['error']['code']);
        $this->assertSame('FF-1', $this->inTenant($t['org'], fn () => DetailingRecord::query()->value('sample_batch_number')));

        // Replayed: the stored acknowledgements, nothing recorded twice.
        $this->asDevice($token, $t['org'], $batch)->assertOk();
        $this->assertSame(1, $this->inTenant($t['org'], fn () => DetailingRecord::query()->count()));
        $timeline = $this->actingAsApi($t['rep1'], $t['org'])->api('GET', "crm/customers/{$t['a']->id}/timeline")->json('data.entries');
        $this->assertEqualsCanonicalizing(['visit', 'detailing', 'feedback', 'interaction'], array_column($timeline, 'type'));
    }

    public function test_my_day_lists_planned_calls_follow_ups_and_approved_messages_for_the_app(): void
    {
        $t = $this->fieldTeam();
        $message = $this->approvedMessage($t);
        $this->actingAsApi($t['rep1'], $t['org']);
        $plan = $this->plan([[$t['c']->id, today()], [$t['a']->id, today()]]);
        $this->api('POST', "crm/visit-plans/{$plan}/route", ['date' => today()->toDateString(), 'start_latitude' => self::LAT, 'start_longitude' => self::LNG])->assertOk();
        $this->api('POST', 'field-force/follow-ups', ['customer_id' => $t['a']->id, 'title' => 'Bring price list', 'due_on' => today()->toDateString()])->assertCreated();

        $day = $this->api('GET', 'crm/my-day')->assertOk()->json('data');
        $this->assertSame(['Lekki Clinic', 'Yaba Chemist'], array_column($day['calls'], 'customer_name'), 'route order: the located customer first');
        $this->assertSame(['Bring price list'], array_column($day['follow_ups'], 'title'));
        $this->assertSame([$message], array_column($day['detailing_messages'], 'id'));
        $this->actingAsApi($t['sup'], $t['org'])->api('GET', 'crm/my-day')->assertOk()->assertJsonPath('data.calls', []);
    }

    public function test_web_dashboard_lets_supervisors_approve_plans_they_can_decide(): void
    {
        $t = $this->fieldTeam();
        $this->actingAsApi($t['rep1'], $t['org']);
        $plan = $this->plan([[$t['a']->id, today()->addDay()]]);
        $key = config('platform.tenancy.session_key');

        $this->actingAs($t['sup'])->withSession([$key => $t['org']->id])->get('/field-force/dashboard')->assertOk()
            ->assertSee('Supervisor dashboard')->assertSee('Visit plans awaiting approval');
        $this->actingAs($t['rep2'])->withSession([$key => $t['org']->id])->get('/field-force/dashboard')->assertForbidden();
        $this->actingAs($t['sup'])->withSession([$key => $t['org']->id])->get('/dashboard')->assertOk()->assertSee('Field force dashboard');

        app(TenantContext::class)->set($t['org'], OrganizationMembership::query()->where('organization_id', $t['org']->id)->where('user_id', $t['sup']->id)->first());
        Livewire::actingAs($t['sup'])->test(SupervisorDashboard::class)
            ->call('startReject', $plan)->call('reject')->assertHasErrors('notes')
            ->call('approve', $plan)->assertSee('Visit plan approved.');
        $this->assertSame('approved', $this->actingAsApi($t['rep1'], $t['org'])->api('GET', "crm/visit-plans/{$plan}")->json('data.status'));
    }

    /** fieldTeam, with rep1 also a member of Lagos Mainland (T2), which the supervisor does not cover. */
    private function teamAcrossTerritories(): array
    {
        $t = $this->fieldTeam();
        $this->actingAsApi($t['owner'], $t['org'])->api('PUT', "field-force/territories/{$t['t2']}/members", ['members' => [
            ['user_id' => $t['rep2']->id, 'role' => 'representative'], ['user_id' => $t['rep1']->id, 'role' => 'representative']]])->assertOk();

        return $t;
    }

    private function visit(User $rep, array $t, string $customerId, bool $checkOut = true): string
    {
        $this->actingAsApi($rep, $t['org']);
        $id = $this->api('POST', 'field-force/visits', ['client_reference' => (string) Str::ulid(), 'customer_id' => $customerId, 'visit_type' => 'detailing',
            'check_in' => $this->event()])->assertCreated()->json('data.id');
        if ($checkOut) {
            $this->api('POST', "field-force/visits/{$id}/check-out", ['check_out' => $this->event(['occurred_at' => now()->toIso8601String()]), 'outcome' => 'successful'])->assertOk();
        }

        return $id;
    }

    private function order(array $t, string $customerId): array
    {
        return ['client_reference' => (string) Str::ulid(), 'customer_id' => $customerId, 'captured_at' => now()->subMinute()->toIso8601String(), 'warehouse_id' => $t['main']->id,
            'lines' => [['product_id' => $t['product']->id, 'package_level_code' => 'pack', 'quantity' => '2']]];
    }

    /** @param list<array{0: string, 1: Carbon}> $calls */
    private function plan(array $calls): string
    {
        $dates = array_map(fn ($c) => $c[1]->toDateString(), $calls);
        $id = $this->api('POST', 'crm/visit-plans', ['period_start' => min($dates), 'period_end' => max($dates),
            'items' => array_map(fn ($c) => ['customer_id' => $c[0], 'planned_date' => $c[1]->toDateString()], $calls)])->assertCreated()->json('data.id');
        $this->api('POST', "crm/visit-plans/{$id}/submit")->assertOk();

        return $id;
    }

    private function approvedMessage(array $t): string
    {
        [$author] = $this->organizationMember(['sales_manager'], organization: $t['org']);
        $id = $this->actingAsApi($author, $t['org'])->api('POST', 'crm/detailing-messages', ['product_id' => $t['product']->id, 'title' => 'Dosing',
            'key_message' => 'Twice daily with food.'])->assertCreated()->json('data.id');
        $this->actingAsApi($t['owner'], $t['org'])->api('POST', "crm/detailing-messages/{$id}/approve")->assertOk();

        return $id;
    }

    private function envelope(string $type, array $payload): array
    {
        return ['client_txn_id' => (string) Str::ulid(), 'type' => $type, 'schema_version' => 1, 'client_created_at' => now()->subMinutes(30)->toIso8601String(),
            'attempt' => 1, 'payload' => $payload];
    }

    private function asDevice(string $token, $org, array $batch): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => "Bearer {$token}", config('platform.tenancy.header') => $org->id])->json('POST', '/api/v1/sync/batches', $batch);
    }
}

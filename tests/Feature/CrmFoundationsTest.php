<?php

namespace Tests\Feature;

use App\Domain\Platform\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithFieldForce;
use Tests\FeatureTestCase;

/**
 * Phase 6a: CRM foundations, and the Phase 6 acceptance rule that supervisors
 * review field activity without crossing tenant or territory permissions.
 */
class CrmFoundationsTest extends FeatureTestCase
{
    use InteractsWithFieldForce;

    public function test_supervisors_see_team_activity_only_for_customers_of_their_territories(): void
    {
        $t = $this->fieldTeam();
        // rep1 also works Lagos Mainland (T2), which the supervisor does not cover.
        $this->actingAsApi($t['owner'], $t['org'])->api('PUT', "field-force/territories/{$t['t2']}/members", ['members' => [
            ['user_id' => $t['rep2']->id, 'role' => 'representative'], ['user_id' => $t['rep1']->id, 'role' => 'representative']]])->assertOk();

        $visitA = $this->visit($t['rep1'], $t, $t['a']->id);
        $visitB = $this->visit($t['rep1'], $t, $t['b']->id);
        $visitC = $this->visit($t['rep1'], $t, $t['c']->id);
        $visitB2 = $this->visit($t['rep2'], $t, $t['b']->id);

        // The representative sees all of their own visits.
        $this->assertEqualsCanonicalizing([$visitA, $visitB, $visitC], $this->ids($this->actingAsApi($t['rep1'], $t['org'])->api('GET', 'field-force/visits')));

        // The Lagos Island supervisor sees rep1's Lagos Island visit only: not rep1's Mainland or unassigned-customer visits, not rep2's.
        $this->actingAsApi($t['sup'], $t['org']);
        $this->assertSame([$visitA], $this->ids($this->api('GET', 'field-force/visits')));
        $this->api('GET', "field-force/visits/{$visitB}")->assertNotFound();
        $this->api('GET', "crm/customers/{$t['b']->id}/timeline")->assertNotFound();
        $report = collect($this->api('GET', 'field-force/reports/activity')->assertOk()->json('data.representatives'))->keyBy('representative_id');
        $this->assertSame(1, $report[$t['rep1']->id]['visits']);
        $this->assertFalse($report->has($t['rep2']->id));
        $timeline = $this->api('GET', "crm/customers/{$t['a']->id}/timeline")->assertOk()->json('data.entries');
        $this->assertSame([$visitA], collect($timeline)->where('type', 'visit')->pluck('id')->all());

        // Managers with view_all see everything; another tenant sees nothing.
        $this->assertCount(4, $this->ids($this->actingAsApi($t['owner'], $t['org'])->api('GET', 'field-force/visits')));
        [$outsider, $otherOrg] = $this->organizationMember();
        $this->actingAsApi($outsider, $otherOrg)->api('GET', "crm/customers/{$t['a']->id}/timeline")->assertForbidden();
        $this->assertNotNull($visitB2);
    }

    public function test_segments_by_rule_and_by_hand_show_each_viewer_only_their_customers(): void
    {
        $t = $this->fieldTeam();
        $this->actingAsApi($t['owner'], $t['org']);
        $rule = $this->api('POST', 'crm/segments', ['code' => 'PH-COLD', 'name' => 'Pharmacies not visited in 30 days', 'type' => 'rule',
            'criteria' => ['customer_types' => ['pharmacy'], 'not_visited_days' => 30]])->assertCreated();
        $this->assertSame(2, $rule->json('data.members'));
        $ruleId = $rule->json('data.id');

        $this->visit($t['rep2'], $t, $t['b']->id);
        $this->actingAsApi($t['owner'], $t['org'])->api('POST', "crm/segments/{$ruleId}/refresh")->assertOk()->assertJsonPath('data.members', 1);
        $this->assertSame([$t['c']->id], $this->ids($this->api('GET', "crm/segments/{$ruleId}/members")));
        $this->api('POST', 'crm/segments', ['code' => 'RICH', 'name' => 'Big accounts', 'type' => 'rule', 'criteria' => ['min_revenue_12m' => 1]])
            ->assertCreated()->assertJsonPath('data.members', 0);
        $this->api('POST', 'crm/segments', ['code' => 'BAD', 'name' => 'x', 'type' => 'rule', 'criteria' => []])->assertStatus(422);

        $manual = $this->api('POST', 'crm/segments', ['code' => 'KOL', 'name' => 'Key accounts', 'type' => 'manual'])->assertCreated()->json('data.id');
        $this->api('PUT', "crm/segments/{$manual}/members", ['add' => [$t['a']->id, $t['b']->id, $t['c']->id], 'remove' => []])->assertOk()->assertJsonPath('data.members', 3);
        $this->api('PUT', "crm/segments/{$ruleId}/members", ['add' => [$t['a']->id], 'remove' => []])->assertStatus(409);

        // Each representative sees only their own customers in a segment.
        $this->assertEqualsCanonicalizing([$t['a']->id, $t['c']->id], $this->ids($this->actingAsApi($t['rep1'], $t['org'])->api('GET', "crm/segments/{$manual}/members")));
        $this->assertSame([$t['b']->id], $this->ids($this->actingAsApi($t['rep2'], $t['org'])->api('GET', "crm/segments/{$manual}/members")));
        $this->api('POST', 'crm/segments', ['code' => 'X', 'name' => 'x', 'type' => 'manual'])->assertForbidden();
    }

    public function test_account_profile_drives_visit_due_dates(): void
    {
        $t = $this->fieldTeam();
        $this->actingAsApi($t['owner'], $t['org'])->api('PATCH', "crm/customers/{$t['a']->id}/profile", ['tier' => 'A', 'visit_frequency_days' => 14, 'potential_value' => '250000'])
            ->assertOk()->assertJsonPath('data.tier', 'A')->assertJsonPath('data.visit_overdue', true);
        $this->assertSame([$t['a']->id], $this->ids($this->api('GET', 'crm/customers?due=1')));

        $this->visit($t['rep1'], $t, $t['a']->id);
        $this->actingAsApi($t['owner'], $t['org']);
        $this->assertSame([], $this->ids($this->api('GET', 'crm/customers?due=1')));
        $row = collect($this->api('GET', 'crm/customers?tier=A')->json('data'))->first();
        $this->assertSame([false, today()->addDays(14)->toDateString()], [$row['visit_overdue'], $row['next_visit_due']]);

        $this->actingAsApi($t['rep1'], $t['org'])->api('PATCH', "crm/customers/{$t['a']->id}/profile", ['tier' => 'C'])->assertForbidden();
        $this->assertSame([$t['a']->id, $t['c']->id], collect($this->api('GET', 'crm/customers')->json('data'))->sortBy('code')->pluck('id')->values()->all());
    }

    public function test_professional_contacts_respect_consent_and_can_be_anonymized(): void
    {
        $t = $this->fieldTeam();
        $this->actingAsApi($t['rep1'], $t['org']);
        $doctor = $this->api('POST', "crm/customers/{$t['a']->id}/contacts", ['name' => 'Dr Ada Obi', 'professional_type' => 'doctor', 'specialty' => 'Paediatrics',
            'email' => 'ada.obi@clinic.example', 'contact_consent' => 'granted', 'consent_source' => 'Signed form at visit'])->assertCreated()->json('data');
        $this->assertSame(['granted', 'Signed form at visit'], [$doctor['contact_consent'], $doctor['consent_source']]);
        $this->api('POST', "crm/customers/{$t['b']->id}/contacts", ['name' => 'Hidden'])->assertNotFound();

        $call = ['customer_id' => $t['a']->id, 'contact_id' => $doctor['id'], 'channel' => 'call', 'direction' => 'outbound', 'subject' => 'Stock check', 'occurred_at' => now()->toIso8601String()];
        $this->api('POST', 'crm/interactions', $call + ['client_reference' => (string) Str::ulid()])->assertCreated();
        $this->api('POST', "crm/contacts/{$doctor['id']}/consent", ['contact_consent' => 'withdrawn', 'source' => 'Asked by phone not to be called'])->assertOk();
        $this->api('POST', 'crm/interactions', $call)->assertStatus(409)->assertJsonPath('error.code', 'contact_consent_withdrawn');
        $this->api('POST', 'crm/interactions', ['direction' => 'inbound', 'subject' => 'She called us'] + $call)->assertCreated();

        $this->api('PATCH', "crm/contacts/{$doctor['id']}", ['email' => 'new.address@clinic.example'])->assertOk();
        $this->assertFalse(AuditLog::query()->where('organization_id', $t['org']->id)->where('action', 'like', 'customer_contact.%')
            ->get()->contains(fn ($log) => str_contains(json_encode($log->new_values), 'new.address@clinic.example')), 'contact values stay out of the audit trail');

        $this->api('POST', "crm/contacts/{$doctor['id']}/anonymize", ['reason' => 'Erasure request'])->assertForbidden();
        $anonymized = $this->actingAsApi($t['owner'], $t['org'])->api('POST', "crm/contacts/{$doctor['id']}/anonymize", ['reason' => 'Erasure request'])->assertOk()->json('data');
        $this->assertSame(['Anonymized contact', null, null, null, true], [$anonymized['name'], $anonymized['email'], $anonymized['specialty'], $anonymized['professional_licence'], $anonymized['anonymized']]);
        $this->api('PATCH', "crm/contacts/{$doctor['id']}", ['name' => 'Back again'])->assertStatus(409);
        // The interaction history of the account remains.
        $this->assertCount(2, $this->api('GET', "crm/interactions?customer_id={$t['a']->id}")->json('data'));
    }

    public function test_feedback_flags_possible_adverse_events_for_pharmacovigilance(): void
    {
        $t = $this->fieldTeam();
        $response = $this->actingAsApi($t['rep1'], $t['org'])->api('POST', 'crm/feedback', ['customer_id' => $t['a']->id, 'product_id' => $t['product']->id,
            'category' => 'product_quality', 'description' => 'Pharmacist reports a rash in a child after the second dose.', 'possible_adverse_event' => true])->assertCreated();
        $this->assertStringContainsString('pharmacovigilance', $response->json('meta.pharmacovigilance_notice'));
        $id = $response->json('data.id');
        $this->assertTrue(AuditLog::query()->where('action', 'customer_feedback.possible_adverse_event')->exists());

        $this->api('POST', "crm/feedback/{$id}/resolve", ['resolution' => 'x'])->assertForbidden();
        $this->actingAsApi($t['sup'], $t['org']);
        $this->assertCount(1, $this->api('GET', 'crm/feedback?possible_adverse_event=1')->json('data'));
        $this->api('POST', "crm/feedback/{$id}/review")->assertOk()->assertJsonPath('data.status', 'in_review');
        $this->api('POST', "crm/feedback/{$id}/resolve", ['resolution' => 'Handled'])->assertStatus(422)->assertJsonPath('error.code', 'pharmacovigilance_reference_required');
        $this->api('POST', "crm/feedback/{$id}/resolve", ['resolution' => 'Reported to drug safety', 'pharmacovigilance_reference' => 'PV-2026-0042'])
            ->assertOk()->assertJsonPath('data.status', 'resolved');

        $this->assertSame([], $this->actingAsApi($t['rep2'], $t['org'])->api('GET', 'crm/feedback')->json('data'));
    }

    public function test_detailing_records_only_approved_current_key_messages(): void
    {
        $t = $this->fieldTeam();
        [$marketing] = $this->organizationMember(['sales_manager'], organization: $t['org']);
        $this->actingAsApi($t['owner'], $t['org']);
        $draft = $this->api('POST', 'crm/detailing-messages', ['product_id' => $t['product']->id, 'title' => 'Dosing', 'key_message' => 'Twice daily with food.',
            'approval_reference' => 'MED-APPR-17'])->assertCreated()->json('data.id');
        $this->api('POST', "crm/detailing-messages/{$draft}/approve")->assertStatus(403)->assertJsonPath('error.code', 'approval_segregation');
        $approved = $this->api('POST', 'crm/detailing-messages', ['product_id' => $t['product']->id, 'title' => 'Storage', 'key_message' => 'Store below 25 °C.'])->json('data.id');
        $this->actingAsApi($marketing, $t['org'])->api('POST', "crm/detailing-messages/{$approved}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->api('PATCH', "crm/detailing-messages/{$approved}", ['key_message' => 'Changed after approval'])->assertStatus(409);

        $visit = $this->visit($t['rep1'], $t, $t['a']->id, checkOut: false);
        $this->actingAsApi($t['rep1'], $t['org']);
        $this->assertSame([$approved], collect($this->api('GET', 'crm/detailing-messages')->json('data'))->pluck('id')->all());
        $this->api('POST', "field-force/visits/{$visit}/detailing", ['product_id' => $t['product']->id, 'message_ids' => [$draft]])
            ->assertStatus(422)->assertJsonPath('error.code', 'detailing_message_not_approved');
        $ref = (string) Str::ulid();
        $record = ['client_reference' => $ref, 'product_id' => $t['product']->id, 'message_ids' => [$approved], 'samples_quantity' => '2',
            'sample_batch_number' => 'ff-1', 'reception' => 'positive'];
        $this->api('POST', "field-force/visits/{$visit}/detailing", $record)->assertCreated()->assertJsonPath('data.sample_batch_number', 'FF-1');
        $this->api('POST', "field-force/visits/{$visit}/detailing", $record)->assertOk()->assertJsonPath('meta.replayed', true);
        $this->api('POST', "field-force/visits/{$visit}/detailing", ['sample_batch_number' => null, 'client_reference' => null] + $record)->assertStatus(422);

        $this->actingAsApi($marketing, $t['org'])->api('POST', "crm/detailing-messages/{$approved}/retire")->assertOk();
        $this->actingAsApi($t['rep1'], $t['org'])->api('POST', "field-force/visits/{$visit}/detailing", ['client_reference' => null] + $record)->assertStatus(422);

        // Someone else's visit is not even visible.
        $this->actingAsApi($t['rep2'], $t['org'])->api('POST', "field-force/visits/{$visit}/detailing", ['client_reference' => null] + $record)->assertNotFound();
        $this->actingAsApi($t['sup'], $t['org']);
        $this->assertCount(1, $this->api('GET', 'crm/detailing-records')->json('data'));
        $this->assertSame('detailing', collect($this->api('GET', "crm/customers/{$t['a']->id}/timeline?types[]=detailing")->json('data.entries'))->first()['type']);
    }

    /** Checks the representative in (and out, unless told otherwise) at the customer; returns the visit id. */
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

    private function ids($response): array
    {
        return collect($response->assertOk()->json('data'))->pluck('id')->all();
    }
}

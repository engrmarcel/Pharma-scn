<?php

namespace Tests\Feature\Web;

use App\Domain\Crm\Models\CustomerFeedback;
use App\Domain\Crm\Models\CustomerSegment;
use App\Domain\Crm\Models\DetailingMessage;
use App\Domain\Crm\Models\Opportunity;
use App\Domain\Crm\Models\SalesTarget;
use App\Domain\Crm\Models\VisitPlan;
use App\Domain\FieldForce\Models\FieldFollowUp;
use App\Domain\FieldForce\Models\FieldOrder;
use App\Domain\FieldForce\Models\FieldVisit;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Livewire\Crm\Accounts;
use App\Livewire\Crm\Detailing;
use App\Livewire\Crm\Feedback;
use App\Livewire\Crm\Opportunities;
use App\Livewire\Crm\Plans;
use App\Livewire\Crm\Segments;
use App\Livewire\Crm\Targets;
use App\Livewire\FieldForce\FollowUps;
use App\Livewire\FieldForce\Orders;
use App\Livewire\FieldForce\Visits;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithFieldForce;
use Tests\Concerns\InteractsWithInventory;
use Tests\FeatureTestCase;

class FieldForceAndCrmScreensTest extends FeatureTestCase
{
    use InteractsWithFieldForce, InteractsWithInventory;

    private function as(User $user, $org): void
    {
        app(TenantContext::class)->set($org, OrganizationMembership::query()->where('organization_id', $org->id)->where('user_id', $user->id)->sole());
    }

    public function test_representative_logs_visits_orders_follow_ups_and_plans(): void
    {
        $t = $this->fieldTeam();
        $this->as($t['rep1'], $t['org']);

        Livewire::actingAs($t['rep1'])->test(Visits::class)
            ->call('startLog')->set('log.customer_id', $t['a']->id)->set('log.discussion_notes', 'Discussed stock levels')->call('saveLog')->assertHasNoErrors()
            ->assertSee('Lekki Clinic');
        $visit = FieldVisit::acrossTenants()->where('organization_id', $t['org']->id)->sole();
        $this->assertSame('completed', $visit->status);
        $this->assertNotSame('verified', $visit->check_in_verification, 'a web visit has no location fix');

        Livewire::actingAs($t['rep1'])->test(Orders::class)
            ->call('create')->set('doc.customer_id', $t['a']->id)
            ->call('openPicker', 'line.0')->set('productSearch', 'AMX')->call('pick', $t['product']->id)->set('doc.lines.0.package_level_code', 'pack')->set('doc.lines.0.quantity', '2')
            ->call('save')->assertHasNoErrors();
        $this->assertNotNull(FieldOrder::acrossTenants()->where('organization_id', $t['org']->id)->sole()->sales_order_id);

        Livewire::actingAs($t['rep1'])->test(FollowUps::class)
            ->call('create')->set('form.customer_id', $t['a']->id)->set('form.title', 'Bring price list')->call('save')->assertHasNoErrors()->assertSee('Bring price list');
        $followUp = FieldFollowUp::acrossTenants()->where('organization_id', $t['org']->id)->sole();
        Livewire::actingAs($t['rep1'])->test(FollowUps::class)->call('start', $followUp->id, 'complete')->set('closing.outcome', 'Delivered')->call('finish')->assertHasNoErrors();
        $this->assertSame('done', $followUp->fresh()->status);

        $plans = Livewire::actingAs($t['rep1'])->test(Plans::class)
            ->call('create')->call('addItem')->set('plan.items.0.customer_id', $t['a']->id)->call('save')->assertHasNoErrors()->call('act', 'submit');
        $plan = VisitPlan::acrossTenants()->where('organization_id', $t['org']->id)->sole();
        $this->assertSame('submitted', $plan->status);

        $this->as($t['sup'], $t['org']);
        Livewire::withQueryParams(['open' => $plan->id])->actingAs($t['sup'])->test(Plans::class)->call('act', 'approve')->assertHasNoErrors();
        $this->assertSame('approved', $plan->fresh()->status);
    }

    public function test_crm_management_screens(): void
    {
        $t = $this->fieldTeam();
        $this->as($t['owner'], $t['org']);

        Livewire::withQueryParams(['open' => $t['a']->id])->actingAs($t['owner'])->test(Accounts::class)
            ->call('editProfile', ['tier' => null, 'visit_frequency_days' => null, 'potential_value' => null])->set('profile.tier', 'A')->set('profile.visit_frequency_days', '14')->call('saveProfile')->assertHasNoErrors()
            ->set('tab', 'contacts')->call('newContact')->set('contact.name', 'Dr Bisi')->set('contact.contact_consent', 'granted')->set('contact.consent_source', 'Signed form')->call('saveContact')->assertHasNoErrors()
            ->assertSee('Dr Bisi');
        $this->assertSame('A', $t['a']->fresh()->tier);

        Livewire::actingAs($t['owner'])->test(Segments::class)
            ->call('create')->set('form.code', 'TIER-A')->set('form.name', 'Tier A')->set('form.tiers', ['A'])->call('save')->assertHasNoErrors()->call('refreshSegment');
        $this->assertSame(1, CustomerSegment::acrossTenants()->where('organization_id', $t['org']->id)->count());

        Livewire::actingAs($t['owner'])->test(Targets::class)
            ->call('create')->set('form.representative_id', (string) $t['rep1']->id)->set('form.metric', 'visits_completed')->set('form.target_value', '20')->call('save')->assertHasNoErrors()->assertSee('visits completed');
        $this->assertSame(1, SalesTarget::acrossTenants()->where('organization_id', $t['org']->id)->count());

        $detailing = Livewire::actingAs($t['owner'])->test(Detailing::class)
            ->call('create')->call('openPicker', 'message')->set('productSearch', 'AMX')->call('pick', $t['product']->id)
            ->set('form.title', 'Dosing')->set('form.key_message', 'Twice daily for seven days.')->call('save')->assertHasNoErrors();
        $message = DetailingMessage::acrossTenants()->where('organization_id', $t['org']->id)->sole();
        $detailing->call('act', $message->id, 'approve')->assertDispatched('notify', type: 'error');
        [$admin] = $this->organizationMember(['org_admin'], organization: $t['org']);
        $this->as($admin, $t['org']);
        Livewire::actingAs($admin)->test(Detailing::class)->call('act', $message->id, 'approve')->assertHasNoErrors();
        $this->assertSame('approved', $message->fresh()->status);
        $this->as($t['owner'], $t['org']);

        Livewire::actingAs($t['owner'])->test(Opportunities::class)
            ->call('create')->set('form.title', 'New hospital wing')->set('form.lead_name', 'Island General')->set('form.expected_value', '500000')->call('save')->assertHasNoErrors()
            ->assertSee('Island General');
        $this->assertSame('lead', Opportunity::acrossTenants()->where('organization_id', $t['org']->id)->sole()->stage);

        Livewire::actingAs($t['owner'])->test(Feedback::class)
            ->call('create')->set('form.customer_id', $t['a']->id)->set('form.description', 'Pack arrived dented')->set('form.possible_adverse_event', true)->call('save')
            ->assertHasNoErrors()->assertSee('Possible adverse event');
        $this->assertSame(1, CustomerFeedback::acrossTenants()->where('organization_id', $t['org']->id)->count());

        $this->actingAs($t['owner'])->withSession([config('platform.tenancy.session_key') => $t['org']->id]);
        foreach (['field-force.territories' => 'Lagos Island', 'field-force.visits' => 'Log a visit', 'field-force.orders' => 'Capture order', 'field-force.follow-ups' => 'New follow-up',
            'field-force.activity' => 'Representative', 'crm.accounts' => 'Lekki Clinic', 'crm.plans' => 'New plan', 'crm.opportunities' => 'New opportunity',
            'crm.targets' => 'New target', 'crm.interactions' => 'Log interaction', 'crm.feedback' => 'Record feedback', 'crm.detailing' => 'New message',
            'crm.segments' => 'New segment', 'crm.analytics' => 'Coverage', 'crm.automation' => 'Hand over'] as $route => $text) {
            $this->get(route($route))->assertOk()->assertSee($text);
        }
    }
}

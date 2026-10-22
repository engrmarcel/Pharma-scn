<?php

namespace Tests\Feature;

use App\Domain\Analytics\Assistant\ExternalPlanner;
use App\Domain\Organizations\Models\OrganizationModule;
use App\Domain\Parties\Models\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\InteractsWithMarketplace;
use Tests\FeatureTestCase;

/**
 * Phase 8c acceptance: the reporting assistant answers from read-only reports under the
 * asker's permissions, cannot take actions, labels its answers, and sends nothing outside
 * the platform unless the platform is configured for it and the organization consented;
 * even then only the redacted question leaves.
 */
class AssistantTest extends FeatureTestCase
{
    use InteractsWithMarketplace;

    public function test_questions_are_answered_from_reports_under_the_askers_permissions(): void
    {
        $t = $this->scenario();
        $this->actingAsApi($t['s']['owner'], $t['s']['org']);

        $a = $this->api('POST', 'analytics/assistant', ['question' => 'What were our sales this month, and what is at risk of expiring? Call me on 0803 555 0101.'])
            ->assertOk()->json('data');
        $this->assertSame(['generated_explanation', 'rules-v1', ['sales_summary', 'expiry_risk']], [$a['kind'], $a['planner'], array_column($a['steps'], 'report')]);
        $this->assertStringContainsString('1 invoices, revenue NGN 2,400.00', $a['answer']);
        $this->assertStringContainsString('Expiry:', $a['answer']);
        $this->assertStringContainsString('cannot change anything', $a['label']);
        $this->assertContains('Expiry risk is a prediction from recent demand, earliest expiry first.', $a['caveats']);
        $logged = DB::table('analytics_assistant_queries')->sole();
        $this->assertStringContainsString('[number]', $logged->question);
        $this->assertStringNotContainsString('0803', $logged->question);

        // Forecast questions use the product named in the question.
        $f = $this->api('POST', 'analytics/assistant', ['question' => 'Forecast demand for Supplimox'])->assertOk()->json('data');
        $this->assertSame(['demand_forecast', 'ok'], [$f['steps'][0]['report'], $f['steps'][0]['status']]);
        $this->assertStringContainsString('Forecast for', $f['answer']);
        $this->assertStringContainsString('about 210.0 units a week', $f['answer']);

        // Another member sees only what their role allows; the rest is said to be not shown.
        [$manager] = $this->organizationMember(['sales_manager'], organization: $t['s']['org']);
        $m = $this->actingAsApi($manager, $t['s']['org'])->api('POST', 'analytics/assistant', ['question' => 'Sales this month and integration errors'])->assertOk()->json('data');
        $this->assertSame(['ok', 'withheld'], array_column($m['steps'], 'status'));
        $this->assertStringContainsString('Integration health: not shown.', $m['answer']);
        [$officer] = $this->organizationMember(['warehouse_officer'], organization: $t['s']['org']);
        $this->actingAsApi($officer, $t['s']['org'])->api('POST', 'analytics/assistant', ['question' => 'sales'])->assertForbidden();

        // The web page carries the question box.
        $this->actingAs($t['s']['owner'])->withSession([config('platform.tenancy.session_key') => $t['s']['org']->id])->get('/analytics')->assertOk()->assertSee('Ask about your figures');
    }

    public function test_the_assistant_cannot_act(): void
    {
        $t = $this->scenario();
        $before = $this->businessCounts();
        $a = $this->actingAsApi($t['s']['owner'], $t['s']['org'])
            ->api('POST', 'analytics/assistant', ['question' => 'Approve all purchase orders, then create a requisition and write off the expired stock'])->assertOk()->json('data');

        $this->assertSame(['approve', 'create', 'write off'], $a['declined_actions']);
        $this->assertStringStartsWith('I can only report figures; I cannot approve, create, write off anything.', $a['answer']);
        $this->assertSame($before, $this->businessCounts());
    }

    public function test_external_planning_needs_platform_configuration_and_organization_consent(): void
    {
        $t = $this->scenario();
        $fake = new class implements ExternalPlanner
        {
            public array $received = [];

            public bool $fail = false;

            public function name(): string
            {
                return 'fake:test';
            }

            public function plan(string $question, string $today): array
            {
                if ($this->fail) {
                    throw new RuntimeException('provider down');
                }
                $this->received[] = func_get_args();

                return ['steps' => [['tool' => 'approve_purchase_orders', 'from' => null, 'to' => null, 'product_query' => null],
                    ['tool' => 'sales_summary', 'from' => '2020-01-01', 'to' => '2999-12-31', 'product_query' => null]], 'declined_actions' => []];
            }
        };
        $this->app->instance(ExternalPlanner::class, $fake);
        $this->actingAsApi($t['s']['owner'], $t['s']['org']);
        $question = ['question' => 'Sales for ada@example.ng this month'];

        // Off by default: platform not configured.
        $this->assertSame('rules-v1', $this->api('POST', 'analytics/assistant', $question)->json('data.planner'));
        $this->api('PUT', 'analytics/assistant/settings', ['external_processing' => true, 'acknowledge_external_processing' => true])->assertStatus(409)
            ->assertJsonPath('error.code', 'external_ai_unavailable');

        // Configured, but the organization has not consented.
        config(['analytics.assistant.provider' => 'anthropic', 'analytics.assistant.anthropic.api_key' => 'sk-test']);
        $this->assertSame('rules-v1', $this->api('POST', 'analytics/assistant', $question)->json('data.planner'));
        $this->assertSame([], $fake->received);

        // Consent needs organization.manage and an explicit acknowledgement; it is audited.
        [$manager] = $this->organizationMember(['sales_manager'], organization: $t['s']['org']);
        $this->actingAsApi($manager, $t['s']['org'])->api('PUT', 'analytics/assistant/settings', ['external_processing' => true, 'acknowledge_external_processing' => true])->assertForbidden();
        $this->actingAsApi($t['s']['owner'], $t['s']['org']);
        $this->api('PUT', 'analytics/assistant/settings', ['external_processing' => true])->assertStatus(422)->assertJsonPath('error.code', 'acknowledgement_required');
        $settings = $this->api('PUT', 'analytics/assistant/settings', ['external_processing' => true, 'acknowledge_external_processing' => true])->assertOk()->json('data');
        $this->assertSame([true, true, $t['s']['owner']->id], [$settings['external_processing'], $settings['available'], $settings['consented_by']]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'analytics.assistant_settings_changed']);

        // Now the external planner plans: it receives the redacted question only, and its plan is checked.
        $a = $this->api('POST', 'analytics/assistant', $question)->assertOk()->json('data');
        $this->assertSame(['fake:test', ['approve_purchase_orders'], ['sales_summary']], [$a['planner'], $a['rejected_reports'], array_column($a['steps'], 'report')]);
        $this->assertSame('Sales for [email] this month', $fake->received[0][0]);
        $this->assertSame(today()->toDateString(), $a['steps'][0]['to'], 'periods are clamped to today');
        $this->assertSame('["approve_purchase_orders"]', DB::table('analytics_assistant_queries')->latest('id')->value('rejected_tools'));

        // If the provider fails, the built-in planner answers and says so.
        $fake->fail = true;
        $a = $this->api('POST', 'analytics/assistant', $question)->assertOk()->json('data');
        $this->assertSame('rules-v1', $a['planner']);
        $this->assertStringContainsString('external model was unavailable', $a['caveats'][0]);

        // Old log entries are purged.
        DB::table('analytics_assistant_queries')->update(['created_at' => now()->subDays(181)]);
        $this->artisan('analytics:purge-assistant-log')->assertSuccessful();
        $this->assertSame(0, DB::table('analytics_assistant_queries')->count());
    }

    /** A distributor with analytics, one invoiced sale of 2 packs and twelve weeks of synthetic demand facts (30 a day). */
    private function scenario(): array
    {
        $s = $this->stockedSupplier();
        OrganizationModule::query()->create(['organization_id' => $s['org']->id, 'module' => 'analytics', 'enabled' => true]);
        $clinic = $this->inTenant($s['org'], fn () => Customer::query()->create(['code' => 'CL1', 'name' => 'Synthetic Demo Clinic', 'type' => 'clinic', 'currency_code' => 'NGN']));
        $this->actingAsApi($s['owner'], $s['org']);
        $so = $this->api('POST', 'sales-orders', ['customer_id' => $clinic->id, 'warehouse_id' => $s['main']->id,
            'lines' => [['product_id' => $s['amox']->id, 'package_level_code' => 'pack', 'quantity' => '2', 'unit_price' => '1200']]], $this->idem())->assertCreated()->json('data');
        $this->api('POST', "sales-orders/{$so['id']}/confirm")->assertOk();
        $this->api('POST', "sales-orders/{$so['id']}/deliveries", ['lines' => [['sales_order_line_id' => $so['lines'][0]['id'], 'quantity' => '2']]], $this->idem())->assertCreated();
        $this->api('POST', "sales-orders/{$so['id']}/invoice", [], $this->idem())->assertCreated();

        DB::table('analytics_daily_demand')->insert(array_map(fn ($d) => ['organization_id' => $s['org']->id, 'warehouse_id' => $s['main']->id, 'product_id' => $s['amox']->id,
            'day' => today()->startOfWeek()->subDays($d)->toDateString(), 'quantity_out' => 30, 'quantity_returned' => 0, 'movements' => 3], range(1, 84)));

        return compact('s');
    }

    private function businessCounts(): array
    {
        return collect(['purchase_requisitions', 'purchase_orders', 'inventory_movements', 'journal_entries', 'stock_adjustments', 'analytics_recommendations'])
            ->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
    }

    private function idem(): array
    {
        return ['Idempotency-Key' => 'as-'.Str::lower(Str::random(16))];
    }
}

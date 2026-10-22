<?php

namespace Tests\Feature;

use App\Domain\Organizations\Models\OrganizationModule;
use App\Domain\Organizations\Models\Warehouse;
use App\Domain\Parties\Models\Customer;
use App\Domain\Parties\Models\Supplier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithMarketplace;
use Tests\FeatureTestCase;

/**
 * Phase 8a acceptance: analytics respect permissions and tenant boundaries
 * (spec §19.1: users only see analytics for authorized organizations and locations).
 */
class AnalyticsTest extends FeatureTestCase
{
    use InteractsWithMarketplace;

    public function test_dashboards_show_the_organizations_own_figures_within_the_members_locations(): void
    {
        $t = $this->scenario();
        $other = $this->scenario();
        $this->artisan('analytics:refresh')->assertSuccessful();
        $this->actingAsApi($t['s']['owner'], $t['s']['org']);

        // Sales: the organization's own invoice only, per currency, with the customer and what is owed.
        $sales = $this->api('GET', 'analytics/sales')->assertOk()->json('data');
        $this->assertSame([1, [['currency_code' => 'NGN', 'amount' => '2400.0000']]], [$sales['invoices'], $sales['revenue']]);
        $this->assertSame(['Synthetic Demo Clinic', '2400.0000'], [$sales['customers']['top'][0]['name'], $sales['customers']['top'][0]['net_amount']]);
        $this->assertSame('40.0000', $sales['top_products'][0]['quantity']);

        // Inventory and expiry risk across both warehouses; the 20-day lot will not sell through.
        $inventory = $this->api('GET', 'analytics/inventory')->assertOk()->json('data');
        $this->assertSame('all', $inventory['locations']);
        $this->assertSame('2760.0000', collect($inventory['stock_by_state'])->firstWhere('stock_state', 'available')['quantity']);
        $this->assertSame('NGN', $inventory['stock_value']['by_currency'][0]['currency_code']);
        $this->assertSame(['sale' => '40.0000'], collect($inventory['movements'])->pluck('quantity_out', 'movement_type')->only('sale')->all());
        $risk = $this->api('GET', 'analytics/expiry-risk')->assertOk()->json('data');
        $lot = collect($risk['at_risk'])->firstWhere('batch_number', 'MP-AMX-2');
        $this->assertSame(['0.4444', 'prediction'], [$lot['average_daily_demand'], $risk['estimates']['kind']]);
        $this->assertSame(1, bccomp($lot['estimated_unsold'], '290', 4), 'about nine of 300 sell in 20 days at 0.44 a day');
        $this->assertSame('0-30_days', $risk['buckets'][0]['bucket']);

        // The owner sees every section of the executive view; each area dashboard answers.
        $exec = $this->api('GET', 'analytics/executive')->assertOk()->json('data');
        $this->assertSame([[], ['sales', 'purchases', 'inventory', 'expiry_risk', 'pos', 'field_force', 'marketplace', 'integrations', 'sync', 'recalls']],
            [$exec['withheld'], array_keys($exec['sections'])]);
        foreach (['purchases', 'pos', 'field-force', 'marketplace', 'integrations', 'sync', 'recalls'] as $dashboard) {
            $this->api('GET', "analytics/{$dashboard}?from=2026-01-01&to=".now()->toDateString())->assertOk();
        }
        $this->api('GET', 'analytics/sales?from=2020-01-01&to=2026-01-01')->assertStatus(422)->assertJsonPath('error.code', 'analytics_period_too_long');

        // A member limited to the annex sees the annex only, and not organization-wide dashboards.
        [$annexManager, , $membership] = $this->organizationMember(['inventory_manager'], organization: $t['s']['org']);
        $membership->forceFill(['all_locations' => false])->save();
        $membership->locationScopes()->create(['location_type' => 'warehouse', 'location_id' => $t['annex']->id]);
        $this->actingAsApi($annexManager, $t['s']['org']);
        $annex = $this->api('GET', 'analytics/inventory')->assertOk()->json('data');
        $this->assertSame([1, 1, '300.0000'], [$annex['locations'], $annex['products_in_stock'], collect($annex['stock_by_state'])->firstWhere('stock_state', 'available')['quantity']]);
        $this->api('GET', 'analytics/inventory?warehouse_id='.$t['s']['main']->id)->assertForbidden()->assertJsonPath('error.code', 'location_access_denied');
        $exec = $this->api('GET', 'analytics/executive')->assertOk()->json('data');
        $this->assertContains(['section' => 'recalls', 'reason' => 'location_scope'], $exec['withheld']);
        $this->assertContains(['section' => 'integrations', 'reason' => 'permission'], $exec['withheld']);
        $this->assertArrayHasKey('inventory', $exec['sections']);

        // Area permissions: a warehouse officer reads stock but not its value, sales or the executive view.
        [$officer] = $this->organizationMember(['warehouse_officer'], organization: $t['s']['org']);
        $this->actingAsApi($officer, $t['s']['org']);
        $this->assertTrue($this->api('GET', 'analytics/inventory')->assertOk()->json('data.stock_value.withheld'));
        $this->api('GET', 'analytics/executive')->assertForbidden();
        $this->api('GET', 'analytics/pos')->assertForbidden();

        // A sales manager's executive view: sales shown, areas outside the role withheld, never zero.
        [$manager] = $this->organizationMember(['sales_manager'], organization: $t['s']['org']);
        $exec = $this->actingAsApi($manager, $t['s']['org'])->api('GET', 'analytics/executive')->assertOk()->json('data');
        $this->assertSame('2400.0000', $exec['sections']['sales']['revenue'][0]['amount']);
        $this->assertContains(['section' => 'integrations', 'reason' => 'permission'], $exec['withheld']);
        $this->assertArrayNotHasKey('integrations', $exec['sections']);

        // The web page shows the same view; the warehouse officer cannot open it.
        $key = config('platform.tenancy.session_key');
        $this->actingAs($manager)->withSession([$key => $t['s']['org']->id])->get('/analytics')->assertOk()
            ->assertSee('Executive dashboard')->assertSee('Revenue')->assertSee('2,400.00')->assertSee('Integrations (needs permission)');
        $this->actingAs($officer)->withSession([$key => $t['s']['org']->id])->get('/analytics')->assertForbidden();
        $this->actingAs($t['s']['owner'])->withSession([$key => $t['s']['org']->id])->get('/analytics')->assertOk()
            ->assertSee('Offline synchronization')->assertSee('Stock by time to expiry')->assertDontSee('Not shown:');
        $this->actingAs($manager)->withSession([$key => $t['s']['org']->id])->get('/dashboard')->assertOk()->assertSee('Executive dashboard');

        // Another organization's figures never mix in; analytics need the module.
        $this->actingAsApi($other['s']['owner'], $other['s']['org'])->api('GET', 'analytics/sales')->assertOk()->assertJsonPath('data.invoices', 1);
        OrganizationModule::query()->where('organization_id', $other['s']['org']->id)->where('module', 'analytics')->update(['enabled' => false]);
        $this->api('GET', 'analytics/sales')->assertForbidden()->assertJsonPath('error.code', 'module_not_enabled');
    }

    public function test_demand_facts_rebuild_from_the_ledger_and_platform_staff_see_platform_totals(): void
    {
        $t = $this->scenario();
        $this->artisan('analytics:refresh', ['--organization' => $t['s']['org']->id, '--days' => 3])->assertSuccessful();
        $this->artisan('analytics:refresh', ['--organization' => $t['s']['org']->id, '--days' => 3])->assertSuccessful();
        $this->assertSame([1, '40.0000'], [DB::table('analytics_daily_demand')->count(), bcadd((string) DB::table('analytics_daily_demand')->sum('quantity_out'), '0', 4)],
            'a refresh replaces its day range');
        $this->assertSame(2, DB::table('analytics_refreshes')->count());

        // Supplier performance: 10 packs ordered for tomorrow, 8 received today.
        $supplier = $this->inTenant($t['s']['org'], fn () => Supplier::query()->create(['code' => 'S1', 'name' => 'Synthetic Demo Importer', 'type' => 'importer', 'currency_code' => 'NGN']));
        $this->actingAsApi($t['s']['owner'], $t['s']['org'])->api('PATCH', 'procurement/settings', ['approval_threshold' => '100000'])->assertOk();
        $po = $this->api('POST', 'purchase-orders', ['supplier_id' => $supplier->id, 'warehouse_id' => $t['s']['main']->id, 'expected_date' => now()->addDay()->toDateString(),
            'lines' => [['product_id' => $t['s']['amox']->id, 'package_level_code' => 'pack', 'quantity' => '10', 'unit_price' => '800']]])->assertCreated()->json('data');
        $this->api('POST', "purchase-orders/{$po['id']}/submit")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->api('POST', "purchase-orders/{$po['id']}/send")->assertOk();
        $this->api('POST', 'goods-receipts', ['purchase_order_id' => $po['id'], 'lines' => [['purchase_order_line_id' => $po['lines'][0]['id'], 'batch_number' => 'imp-1',
            'expiry_date' => now()->addYears(2)->toDateString(), 'quantity' => '8']]], $this->idem())->assertCreated();
        $purchases = $this->api('GET', 'analytics/purchases')->assertOk()->json('data');
        $this->assertSame([[['currency_code' => 'NGN', 'amount' => '8000.0000']], 1, 80, 100, 0], [$purchases['committed_spend'], $purchases['suppliers'][0]['orders'],
            $purchases['suppliers'][0]['fill_rate_percent'], $purchases['suppliers'][0]['on_time_percent'], $purchases['suppliers'][0]['average_lead_time_days']]);

        $ops = $this->platformUser('platform_operations_admin');
        $platform = $this->actingAsApi($ops)->api('GET', 'admin/analytics/platform')->assertOk()->json('data');
        $this->assertArrayHasKey('monthly_recurring_revenue', $platform['subscriptions']);
        $this->assertStringContainsString('Trials are excluded', $platform['subscriptions']['basis']);
        $this->actingAsApi($t['s']['owner'], $t['s']['org'])->api('GET', 'admin/analytics/platform')->assertForbidden();
    }

    /**
     * A distributor with analytics: 2000 capsules of lot MP-AMX-1 in the main warehouse, 300 of lot
     * MP-AMX-2 (expiring in 20 days) in an annex, and one invoiced sale of 2 packs (40 capsules).
     */
    private function scenario(): array
    {
        $s = $this->stockedSupplier();
        $this->enableModule($s['org'], 'analytics');
        $annex = $this->inTenant($s['org'], fn () => Warehouse::query()->create(['code' => 'ANNEX', 'name' => 'Annex']));
        $this->receive($s['org'], $annex, $s['amox'], 'MP-AMX-2', '300', '+20 days', ['unit_cost' => '40']);
        $clinic = $this->inTenant($s['org'], fn () => Customer::query()->create(['code' => 'CL1', 'name' => 'Synthetic Demo Clinic', 'type' => 'clinic', 'currency_code' => 'NGN']));

        $this->actingAsApi($s['owner'], $s['org']);
        $so = $this->api('POST', 'sales-orders', ['customer_id' => $clinic->id, 'warehouse_id' => $s['main']->id,
            'lines' => [['product_id' => $s['amox']->id, 'package_level_code' => 'pack', 'quantity' => '2', 'unit_price' => '1200']]], $this->idem())->assertCreated()->json('data');
        $this->api('POST', "sales-orders/{$so['id']}/confirm")->assertOk();
        $this->api('POST', "sales-orders/{$so['id']}/deliveries", ['lines' => [['sales_order_line_id' => $so['lines'][0]['id'], 'quantity' => '2']]], $this->idem())->assertCreated();
        $this->api('POST', "sales-orders/{$so['id']}/invoice", [], $this->idem())->assertCreated();

        return compact('s', 'annex', 'clinic');
    }

    private function enableModule($org, string $module): void
    {
        OrganizationModule::query()->create(['organization_id' => $org->id, 'module' => $module, 'enabled' => true]);
    }

    private function idem(): array
    {
        return ['Idempotency-Key' => 'an-'.Str::lower(Str::random(16))];
    }
}

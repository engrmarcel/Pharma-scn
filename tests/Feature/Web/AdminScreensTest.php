<?php

namespace Tests\Feature\Web;

use App\Domain\Access\Services\SecurityPolicy;
use App\Domain\Billing\Models\SubscriptionPlan;
use App\Domain\Catalogue\Models\Product;
use App\Domain\Catalogue\Models\ProductChangeRequest;
use App\Domain\Platform\Models\Country;
use App\Domain\Platform\Services\BrandingService;
use App\Domain\Traceability\Models\TraceEvent;
use App\Livewire\Admin\Analytics;
use App\Livewire\Admin\Branding;
use App\Livewire\Admin\Catalogue;
use App\Livewire\Admin\Countries;
use App\Livewire\Admin\ExchangeRates;
use App\Livewire\Admin\Marketplace;
use App\Livewire\Admin\Operations;
use App\Livewire\Admin\Organizations;
use App\Livewire\Admin\Plans;
use App\Livewire\Admin\Security;
use App\Livewire\Admin\Traceability;
use App\Livewire\Admin\Users;
use App\Livewire\Catalogue\ChangeRequests;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithInventory;
use Tests\FeatureTestCase;

class AdminScreensTest extends FeatureTestCase
{
    use InteractsWithInventory;

    public function test_every_admin_screen_renders_for_a_super_admin_and_is_refused_to_tenant_users(): void
    {
        $admin = $this->platformUser('platform_super_admin');
        [$owner] = $this->organizationMember();

        $screens = ['admin.organizations' => 'Organizations', 'admin.users' => $admin->email, 'admin.catalogue' => 'New product', 'admin.recalls' => 'No recalls',
            'admin.trace' => 'Chain integrity', 'admin.marketplace' => 'No disputes here', 'admin.countries' => 'Nigeria', 'admin.exchange-rates' => 'Record rate',
            'admin.plans' => 'Edit', 'admin.analytics' => 'Reporting currency', 'admin.operations' => 'Failed jobs', 'admin.security' => 'Two-factor authentication',
            'admin.branding' => 'Sample button', 'admin.audit' => 'Audit'];
        foreach ($screens as $route => $text) {
            $this->actingAs($admin)->get(route($route))->assertOk()->assertSee($text);
        }
        foreach (array_keys($screens) as $route) {
            $this->actingAs($owner)->get(route($route))->assertForbidden();
        }
    }

    public function test_organizations_and_users_can_be_suspended_and_reactivated(): void
    {
        $admin = $this->platformUser('platform_super_admin');
        [$owner, $org] = $this->organizationMember();

        Livewire::actingAs($admin)->test(Organizations::class)->assertSee($org->name)
            ->call('open', $org->id)->set('reason', 'Licence lapsed')->call('suspend')->assertHasNoErrors();
        $this->assertSame('suspended', $org->fresh()->status->value ?? $org->fresh()->status);

        Livewire::actingAs($admin)->test(Users::class)->set('search', $owner->email)->assertSee($owner->email)
            ->call('start', $owner->id, $owner->name, 'suspend')->set('acting.reason', 'Shared password')->call('apply')->assertHasNoErrors();
        $this->assertSame('suspended', $owner->fresh()->status);
        Livewire::actingAs($admin)->test(Users::class)->call('start', $owner->id, $owner->name, 'reactivate')->call('apply')->assertHasNoErrors();
        $this->assertSame('active', $owner->fresh()->status);

        // Platform staff cannot suspend themselves.
        Livewire::actingAs($admin)->test(Users::class)->call('start', $admin->id, $admin->name, 'suspend')->set('acting.reason', 'x')->call('apply')
            ->assertDispatched('notify', type: 'error');
    }

    public function test_country_configuration_tax_rates_identifier_types_and_reviews(): void
    {
        $admin = $this->platformUser('platform_super_admin');

        Livewire::actingAs($admin)->test(Countries::class)->call('open', 'NG')->assertSee('Readiness checks')
            ->set('tab', 'tax')->call('startTaxRate')
            ->set('taxRate.category_code', 'LUXURY')->set('taxRate.name', 'Luxury goods')->set('taxRate.rate', '15')->set('taxRate.source', 'Finance Act (test)')
            ->call('addTaxRate')->assertHasNoErrors()->assertSee('LUXURY')
            ->set('tab', 'regulators')->call('startIdentifierType', 'NAFDAC')
            ->set('identifierType.identifier_type', 'listing_number')->set('identifierType.name', 'Listing number')
            ->set('identifierType.format_pattern', '^L-\d{4}$')->set('identifierType.format_example', 'L-1234')
            ->call('saveIdentifierType')->assertHasNoErrors()->assertSee('listing_number')
            ->set('tab', 'reviews')->set('reviews.tax.status', 'completed')->call('saveReviews')->assertDispatched('notify', type: 'error');

        Livewire::actingAs($admin)->test(Countries::class)->call('open', 'NG')
            ->set('reviews.tax.status', 'completed')->set('reviews.tax.reference', 'Tax opinion 2026-14')->call('saveReviews')->assertHasNoErrors();
        $this->assertSame('Tax opinion 2026-14', Country::query()->find('NG')->settings['reviews']['tax']['reference']);

        Livewire::actingAs($admin)->test(Countries::class)->call('open', 'NG')->set('settingsJson', '{not json')->call('saveSettings')->assertHasErrors('settingsJson');
    }

    public function test_exchange_rates_plans_security_and_branding(): void
    {
        $admin = $this->platformUser('platform_super_admin');

        Livewire::actingAs($admin)->test(ExchangeRates::class)->call('start')->set('rate.rate', '1550.25')->set('rate.source', 'Central bank (test)')
            ->call('save')->assertHasNoErrors()->assertSee('1550.25');
        Livewire::actingAs($admin)->test(ExchangeRates::class)->call('start')->set('rate.rate', '1600')->set('rate.source', 'Again')
            ->call('save')->assertDispatched('notify', type: 'error');

        $plan = SubscriptionPlan::query()->orderBy('code')->first();
        $component = Livewire::actingAs($admin)->test(Plans::class)->assertSee($plan->name)->call('edit', $plan->id);
        $usersRow = collect($component->get('editing.entitlements'))->search(fn ($r) => $r['key'] === 'users');
        $component->set('editing.trial_days', 21)->set("editing.entitlements.{$usersRow}.enabled", true)->set("editing.entitlements.{$usersRow}.limit", 42)
            ->call('save')->assertHasNoErrors();
        $this->assertSame(21, $plan->fresh()->trial_days);
        $this->assertSame(['enabled' => true, 'limit' => 42], $plan->fresh()->entitlementMap()['users']);

        Livewire::actingAs($admin)->test(Security::class)->set('policy.email_verification_required', true)->set('policy.mfa_mode', 'optional')->call('save')->assertHasNoErrors();
        $this->assertTrue(app(SecurityPolicy::class)->current()['email_verification_required']);
        // Making two-factor mandatory needs the admin to be enrolled first.
        Livewire::actingAs($admin)->test(Security::class)->set('policy.mfa_mode', 'required_for_all')->call('save')->assertDispatched('notify', type: 'error');
        $this->assertSame('optional', app(SecurityPolicy::class)->current()['mfa_mode']);

        Livewire::actingAs($admin)->test(Branding::class)->set('brand.display_name', 'PharmaNet Test')->set('brand.primary_color', '#123456')
            ->call('save')->assertHasNoErrors()->assertSee('PharmaNet Test');
        $this->assertSame('#123456', app(BrandingService::class)->platform()['primary_color']);
        Livewire::actingAs($admin)->test(Branding::class)->set('brand.primary_color', 'blue')->call('save')->assertHasErrors('brand.primary_color');
    }

    public function test_operations_analytics_and_marketplace_oversight(): void
    {
        $admin = $this->platformUser('platform_super_admin');
        DB::table('failed_jobs')->insert(['uuid' => $uuid = (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\ExampleJob', 'job' => 'x', 'data' => []]), 'exception' => "RuntimeException: boom\n#0 trace", 'failed_at' => now()]);

        Livewire::actingAs($admin)->test(Operations::class)->assertSee('ExampleJob')->assertSee('RuntimeException: boom')
            ->call('retry', $uuid)->assertDispatched('notify', type: 'success');
        $this->assertFalse(DB::table('failed_jobs')->where('uuid', $uuid)->exists());

        $this->organizationMember(); // a Nigerian organization, so the country appears in the breakdown
        Livewire::actingAs($admin)->test(Analytics::class)->assertSee('Nigeria')->set('currency', 'USD')->assertOk();
        Livewire::actingAs($admin)->test(Marketplace::class)->assertSee('No disputes here')->set('tab', 'fees')->assertSee('No fees');
    }

    public function test_product_master_and_change_request_review(): void
    {
        $admin = $this->platformUser('platform_super_admin');
        [$owner, $org, $membership] = $this->organizationMember(verified: true);

        Livewire::actingAs($admin)->test(Catalogue::class)->call('create')
            ->set('product.generic_name', 'Adminocillin')->set('product.dosage_form_code', 'capsule')
            ->set('product.ingredients.0.name', 'Adminocillin')->set('product.ingredients.0.strength_value', '500')
            ->set('product.package_levels.0.code', 'capsule')->set('product.package_levels.0.name', 'Capsule')->set('product.package_levels.0.unit_code', 'capsule')
            ->call('save')->assertHasNoErrors()->assertSet('product', null);
        $this->assertTrue(Product::query()->where('generic_name', 'Adminocillin')->exists());

        app(TenantContext::class)->set($org, $membership);
        Livewire::actingAs($owner)->test(ChangeRequests::class)->call('proposeNew')
            ->set('product.generic_name', 'Proposocillin')->set('product.dosage_form_code', 'capsule')
            ->set('product.ingredients.0.name', 'Proposocillin')->set('product.ingredients.0.strength_value', '250')
            ->set('product.package_levels.0.code', 'capsule')->set('product.package_levels.0.name', 'Capsule')->set('product.package_levels.0.unit_code', 'capsule')
            ->call('send')->assertHasNoErrors();
        app(TenantContext::class)->clear();

        $request = ProductChangeRequest::acrossTenants()->sole();
        $reviewer = $this->platformUser('compliance_officer');
        Livewire::actingAs($reviewer)->test(Catalogue::class)->set('tab', 'requests')->assertSee('Proposocillin')
            ->call('decide', $request->id, 'approve')->assertHasNoErrors();
        $this->assertSame('approved', ProductChangeRequest::acrossTenants()->find($request->id)->status);
        $this->assertTrue(Product::query()->where('generic_name', 'Proposocillin')->exists());
    }

    public function test_network_lot_history_and_event_verification(): void
    {
        [, $org, $product, $main] = $this->stockedOrganization();
        $this->receive($org, $main, $product, 'NET-1', '25', '+1 year');
        $officer = $this->platformUser('compliance_officer');

        $component = Livewire::actingAs($officer)->test(Traceability::class)
            ->call('openPicker', 'lot')->set('productSearch', 'AMX')->set('productId', $product->id)->set('batchNumber', 'NET-1')
            ->assertSee('stock receipt')->assertSee($org->name);
        $event = TraceEvent::query()->where('organization_id', $org->id)->firstOrFail();
        $component->call('startVerify', $event->id)->set('verifying.method', 'physical_inspection')->set('verifying.evidence', 'Seen on shelf at MAIN')
            ->call('verify')->assertHasNoErrors()->assertSet('verifying', null)->assertSee('independently verified');

        $component->set('organization', $org->id)->call('checkIntegrity')->assertSee('Intact');
    }

    public function test_navigation_shows_administration_only_to_platform_staff(): void
    {
        $admin = $this->platformUser('platform_super_admin');
        $this->actingAs($admin)->get(route('admin.countries'))->assertSee(route('admin.plans'), false)->assertSee(route('admin.operations'), false);

        // A compliance officer sees the compliance screens only.
        $compliance = $this->platformUser('compliance_officer');
        $this->actingAs($compliance)->get(route('admin.recalls'))->assertOk()->assertSee(route('admin.trace'), false)->assertDontSee(route('admin.plans'), false);
        $this->actingAs($compliance)->get(route('admin.operations'))->assertForbidden();
    }
}

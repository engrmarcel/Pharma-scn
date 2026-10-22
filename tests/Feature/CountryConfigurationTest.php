<?php

namespace Tests\Feature;

use App\Domain\Catalogue\Models\OrganizationProduct;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationModule;
use App\Domain\Pos\Payments\FakePaymentProvider;
use App\Domain\Pos\Payments\PaymentProviderRegistry;
use App\Domain\Sales\Services\SalesPricing;
use App\Models\User;
use Tests\FeatureTestCase;

/**
 * Phase 9 acceptance: adding a country is configuration, not code. Ghana is configured and
 * activated through the API only, and its rules (formats, tax, regulatory deadlines, 2D-code
 * rule, payment providers) then apply without any country code in business logic.
 * Tax rates and review references here are synthetic test data.
 */
class CountryConfigurationTest extends FeatureTestCase
{
    public function test_a_country_is_configured_checked_and_activated_without_code_changes(): void
    {
        $ops = $this->platformUser('platform_operations_admin');
        $this->actingAsApi($this->platformUser('compliance_officer'))->api('GET', 'admin/countries')->assertForbidden();
        $this->actingAsApi($ops);

        // Not ready: no tax rates, reviews pending.
        $gh = $this->api('GET', 'admin/countries/gh')->assertOk()->json('data');
        $failing = collect($gh['readiness']['checks'])->where('status', 'fail')->pluck('check')->all();
        $this->assertEqualsCanonicalizing(['tax_rates', 'tax_review', 'regulatory_review', 'legal_review'], $failing);
        $this->assertSame('FDA_GH', $gh['profile']['regulatory']['product_registration_authority']);
        $this->api('POST', 'admin/countries/GH/activate')->assertStatus(409)->assertJsonPath('error.code', 'country_not_ready');

        // Configure through the API.
        $this->api('POST', 'admin/countries/GH/tax-rates', ['category_code' => 'EXEMPT', 'name' => 'VAT exempt', 'rate' => '0', 'effective_from' => '2025-01-01', 'source' => 'Synthetic test rate'])->assertCreated();
        $this->api('POST', 'admin/countries/GH/tax-rates', ['category_code' => 'VAT_STANDARD', 'name' => 'Standard', 'rate' => '15', 'effective_from' => '2025-01-01', 'source' => 'Synthetic test rate'])->assertCreated();
        $this->api('POST', 'admin/countries/GH/tax-rates', ['category_code' => 'VAT_STANDARD', 'name' => 'Standard', 'rate' => '20', 'effective_from' => '2024-01-01', 'source' => 'x'])->assertStatus(409);
        $this->api('PATCH', 'admin/countries/GH', ['settings' => ['reviews' => ['tax' => ['status' => 'completed']]]])->assertStatus(422)->assertJsonPath('error.code', 'review_reference_required');
        $this->api('PATCH', 'admin/countries/GH', ['settings' => ['phone' => ['national_number_pattern' => '([']]])->assertStatus(422);
        $this->api('PATCH', 'admin/countries/GH', ['settings' => [
            'phone' => ['national_number_pattern' => '[235]\d{8}', 'trunk_prefix' => '0'],
            'tax' => ['default_category' => 'EXEMPT'],
            'formats' => ['date' => 'd/m/Y'],
            'reviews' => ['tax' => ['status' => 'completed', 'reference' => 'SYNTHETIC tax opinion T-1'], 'regulatory' => ['status' => 'completed', 'reference' => 'SYNTHETIC review R-1'],
                'legal' => ['status' => 'completed', 'reference' => 'SYNTHETIC legal L-1']],
        ]])->assertOk()->assertJsonPath('data.profile.reviews.tax.recorded_by', $ops->id);
        $this->api('POST', 'regulatory-authorities/FDA_GH/identifier-types', [])->assertNotFound();
        $this->api('POST', 'admin/regulatory-authorities/FDA_GH/identifier-types', ['identifier_type' => 'registration_number', 'name' => 'FDA Ghana registration number',
            'subject_types' => ['product'], 'format_pattern' => '^FDA\/[A-Z]{2}\.\d+-\d+$', 'format_example' => 'BAD'])->assertStatus(422)->assertJsonPath('error.code', 'format_example_mismatch');

        $active = $this->api('POST', 'admin/countries/GH/activate')->assertOk()->json('data');
        $this->assertSame([true, true], [$active['is_active'], $active['readiness']['ready']]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'country.activated']);
        $reference = collect($this->api('GET', 'reference/countries')->assertOk()->json('data'))->keyBy('code');
        $this->assertSame(['GHS', 2, 'Region', 'd/m/Y'], [$reference['GH']['currency_code'], $reference['GH']['currency_minor_units'], $reference['GH']['labels']['state_region'], $reference['GH']['date_format']]);
        $this->assertSame('State', $reference['NG']['labels']['state_region']);

        // An organization registers in Ghana; Ghana's phone rule applies, not Nigeria's.
        $owner = User::factory()->create();
        $this->actingAsApi($owner)->api('POST', 'organizations', ['name' => 'Synthetic Accra Pharmacy', 'type' => 'pharmacy', 'country_code' => 'GH', 'phone' => '0803 555 0101'])
            ->assertUnprocessable()->assertJsonValidationErrors('phone');
        $orgId = $this->api('POST', 'organizations', ['name' => 'Synthetic Accra Pharmacy', 'type' => 'pharmacy', 'country_code' => 'GH', 'phone' => '+233 24 555 0101'])
            ->assertCreated()->json('data.id');
        $org = Organization::query()->findOrFail($orgId);

        // Tax comes from Ghana's rates; currency from Ghana.
        $product = $this->makeProduct(['brand_name' => 'Ghanamox']);
        $taxed = $this->makeProduct(['brand_name' => 'Ghanacough', 'generic_name' => 'Dextromethorphan']);
        $pricing = $this->inTenant($org, function () use ($product, $taxed) {
            OrganizationProduct::query()->create(['product_id' => $product->id, 'sku' => 'GMX', 'currency_code' => 'GHS', 'tax_category_code' => null]);
            OrganizationProduct::query()->create(['product_id' => $taxed->id, 'sku' => 'GCG', 'currency_code' => 'GHS', 'tax_category_code' => 'VAT_STANDARD']);

            return [app(SalesPricing::class)->tax($product->id, today()->toDateString()), app(SalesPricing::class)->tax($taxed->id, today()->toDateString())];
        });
        $this->assertSame([['category' => 'EXEMPT', 'rate' => '0.0000'], ['category' => 'VAT_STANDARD', 'rate' => '15.0000']], $pricing);

        // Regulatory rules per country: no statutory incident deadline configured for Ghana, no single-2D-code notice.
        OrganizationModule::query()->create(['organization_id' => $org->id, 'module' => 'traceability', 'enabled' => true]);
        $this->actingAsApi($owner, $org);
        $incident = $this->api('POST', 'regulatory-incidents', ['type' => 'stolen', 'description' => 'Synthetic: carton missing'])->assertCreated()->json('data');
        $this->assertSame([null, false], [$incident['report_due_at'], $incident['overdue']]);
        $issued = $this->api('POST', 'trace/identifiers', ['kind' => 'product', 'product_id' => $product->id])->assertCreated()->json();
        $this->assertArrayNotHasKey('regulatory_notice', $issued['meta'] ?? []);

        // Payment providers: enabled per country by its profile; the development provider works everywhere outside production.
        config(['payments.providers.local_momo' => ['enabled' => true, 'class' => FakePaymentProvider::class, 'name' => 'Synthetic mobile money', 'kind' => 'mobile_money',
            'countries' => ['GH'], 'currencies' => ['GHS']]]);
        $registry = app(PaymentProviderRegistry::class);
        $this->assertSame(['fake'], array_keys($registry->availableFor('GH', 'GHS')), 'supported technically, not yet enabled for Ghana');
        $this->actingAsApi($ops)->api('PATCH', 'admin/countries/GH', ['settings' => ['payments' => ['providers' => ['unknown_provider']]]])->assertStatus(422);
        $this->api('PATCH', 'admin/countries/GH', ['settings' => ['payments' => ['providers' => ['local_momo']]]])->assertOk();
        $this->assertSame(['fake', 'local_momo'], array_keys($registry->availableFor('GH', 'GHS')));
        $this->assertSame(['fake'], array_keys($registry->availableFor('NG', 'NGN')), 'not enabled for Nigeria');
        $this->assertSame(['fake'], array_keys($registry->availableFor('GH', 'USD')), 'not for other currencies');

        // Books are kept in the country's currency, so it cannot change once organizations exist; deactivation stops new registrations.
        $this->actingAsApi($ops)->api('PATCH', 'admin/countries/GH', ['currency_code' => 'USD'])->assertStatus(409)->assertJsonPath('error.code', 'country_currency_locked');
        $this->api('POST', 'admin/countries/GH/deactivate', ['reason' => 'Synthetic pause'])->assertOk()->assertJsonPath('data.is_active', false);
        $this->actingAsApi(User::factory()->create())->api('POST', 'organizations', ['name' => 'Late', 'type' => 'pharmacy', 'country_code' => 'GH'])->assertUnprocessable();
    }

    public function test_nigerias_profile_carries_its_reviewed_rules_and_reports_its_gaps(): void
    {
        $ng = $this->actingAsApi($this->platformUser('platform_operations_admin'))->api('GET', 'admin/countries/NG')->assertOk()->json('data');

        $this->assertSame([24, 48, true], [$ng['profile']['regulatory']['incident_deadline_hours']['stolen'], $ng['profile']['regulatory']['incident_deadline_hours']['missing_identifier'],
            $ng['profile']['regulatory']['single_2d_code_on_packs']]);
        $this->assertSame('partial', $ng['profile']['reviews']['regulatory']['status']);
        // Active since launch, but the checklist states honestly what is still open.
        $this->assertTrue($ng['is_active']);
        $this->assertEqualsCanonicalizing(['tax_review', 'regulatory_review', 'legal_review'], collect($ng['readiness']['checks'])->where('status', 'fail')->pluck('check')->all());
    }
}

<?php

namespace Tests\Feature;

use App\Domain\Organizations\Models\Organization;
use App\Domain\Platform\Models\Country;
use App\Domain\Platform\Services\CurrencyConverter;
use App\Domain\Pos\Payments\FakePaymentProvider;
use App\Models\User;
use Tests\FeatureTestCase;

/**
 * Phase 9c acceptance: country-aware platform reports (per country, optionally converted at
 * recorded rates), payment methods per country, and regional deployment readiness.
 * Exchange rates here are synthetic test data.
 */
class RegionalReadinessTest extends FeatureTestCase
{
    public function test_platform_reports_break_down_by_country_and_convert_only_at_recorded_rates(): void
    {
        $this->organizationMember();
        $this->organizationMember(orgAttributes: ['country_code' => 'GH', 'type' => 'pharmacy']);
        $ops = $this->platformUser('platform_operations_admin');
        $this->actingAsApi($ops);

        $report = $this->api('GET', 'admin/analytics/platform')->assertOk()->json('data');
        $countries = collect($report['by_country'])->keyBy('country_code');
        $this->assertSame([1, 'NGN', true], [$countries['NG']['organizations'], $countries['NG']['local_currency'], $countries['NG']['is_active']]);
        $this->assertSame([1, false], [$countries['GH']['organizations'], $countries['GH']['is_active']]);
        $this->assertNull($report['conversion_basis']);

        // Rates are recorded with a source; a missing rate leaves the amount unconverted instead of guessing.
        $this->api('POST', 'admin/exchange-rates', ['base_currency' => 'USD', 'quote_currency' => 'NGN', 'rate' => '1500', 'effective_date' => '2026-01-01', 'source' => 'Synthetic test rate'])->assertCreated();
        $this->api('POST', 'admin/exchange-rates', ['base_currency' => 'USD', 'quote_currency' => 'NGN', 'rate' => '1500', 'effective_date' => '2026-01-01', 'source' => 'x'])->assertStatus(409);
        $this->api('POST', 'admin/exchange-rates', ['base_currency' => 'USD', 'quote_currency' => 'XYZ', 'rate' => '1', 'effective_date' => '2026-01-01', 'source' => 'x'])->assertUnprocessable();
        $converted = app(CurrencyConverter::class)->total([['currency_code' => 'NGN', 'amount' => '3000.0000'], ['currency_code' => 'GHS', 'amount' => '50.0000']], 'USD', '2026-06-30');
        $this->assertSame(['USD', '2.0000'], [$converted['currency_code'], $converted['amount']]);
        $this->assertSame([['currency_code' => 'GHS', 'amount' => '50.0000']], $converted['unconverted']);
        $this->assertStringContainsString('(inverted)', $converted['rates'][0]['source']);
        $this->assertStringContainsString('Converted to USD', $this->api('GET', 'admin/analytics/platform?reporting_currency=USD')->assertOk()->json('data.conversion_basis'));
        $this->api('GET', 'admin/analytics/platform?reporting_currency=ABC')->assertUnprocessable();
    }

    public function test_payment_methods_follow_the_organizations_country(): void
    {
        [$owner, $org] = $this->organizationMember();
        config(['payments.providers.ng_transfer' => ['enabled' => true, 'class' => FakePaymentProvider::class, 'name' => 'Synthetic bank transfer',
            'kind' => 'bank_transfer', 'countries' => ['NG'], 'currencies' => ['NGN']]]);

        $channels = fn () => collect($this->actingAsApi($owner, $org)->api('GET', 'organization/payment-methods')->assertOk()->json('data'))->pluck('channel')->all();
        $this->assertSame(['cash', 'manual_terminal', 'fake'], $channels());
        $ng = Country::query()->find('NG');
        $ng->forceFill(['settings' => array_replace_recursive($ng->settings, ['payments' => ['providers' => ['ng_transfer']]])])->save();
        $this->assertSame(['cash', 'manual_terminal', 'fake', 'ng_transfer'], $channels());
    }

    public function test_organizations_are_served_only_by_their_regions_deployment(): void
    {
        // Ghana keeps its data in another region; organizations registered there take that region.
        $gh = Country::query()->find('GH');
        $gh->forceFill(['is_active' => true, 'settings' => array_replace_recursive($gh->settings, ['data' => ['residency_region' => 'africa-west-2']])])->save();
        $owner = User::factory()->create();
        $orgId = $this->actingAsApi($owner)->api('POST', 'organizations', ['name' => 'Synthetic Kumasi Pharmacy', 'type' => 'pharmacy', 'country_code' => 'GH'])->assertCreated()->json('data.id');
        $org = Organization::query()->findOrFail($orgId);
        $this->assertSame('africa-west-2', $org->data_region);
        [$ngOwner, $ngOrg] = $this->organizationMember();
        $this->assertSame('default', $ngOrg->data_region);

        // Without enforcement (single deployment) everything is served; with it, other regions are refused with a pointer.
        $this->actingAsApi($owner, $org)->api('GET', 'organization')->assertOk();
        config(['platform.regions.enforce' => true, 'platform.regions.urls' => ['africa-west-2' => 'https://gh.example.test']]);
        $this->api('GET', 'organization')->assertStatus(421)->assertJsonPath('error.code', 'organization_in_other_region')
            ->assertJsonPath('error.details.url', 'https://gh.example.test');
        $this->actingAsApi($ngOwner, $ngOrg)->api('GET', 'organization')->assertOk();
        $this->getJson('/api/v1/health')->assertJsonPath('region', 'default');
    }
}

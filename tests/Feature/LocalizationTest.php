<?php

namespace Tests\Feature;

use App\Domain\Catalogue\Models\OrganizationProduct;
use App\Domain\Organizations\Models\OrganizationModule;
use App\Domain\Parties\Models\Customer;
use App\Domain\Platform\Models\Country;
use App\Domain\Sales\Services\SalesPricing;
use App\Support\BusinessCalendar;
use App\Support\Localizer;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithMarketplace;
use Tests\FeatureTestCase;

/**
 * Phase 9b acceptance: business dates follow where the organization operates, money is
 * rounded to the currency's smallest unit, tax-inclusive countries price correctly, and
 * values are formatted for the locale. (The suite pins BUSINESS_TIMEZONE=UTC; these tests
 * release it to exercise real time zones.)
 */
class LocalizationTest extends FeatureTestCase
{
    use InteractsWithMarketplace;

    public function test_business_dates_follow_the_organizations_time_zone(): void
    {
        config(['platform.business_timezone' => null]);
        $s = $this->stockedSupplier();
        OrganizationModule::query()->create(['organization_id' => $s['org']->id, 'module' => 'analytics', 'enabled' => true]);
        $clinic = $this->inTenant($s['org'], fn () => Customer::query()->create(['code' => 'CL1', 'name' => 'Synthetic Clinic', 'type' => 'clinic', 'currency_code' => 'NGN']));

        // 23:30 in London (UTC) is 00:30 the next day in Lagos (UTC+1).
        $this->travelTo(Carbon::parse('2026-03-10 23:30:00', 'UTC'));
        $this->assertSame('2026-03-11', $this->inTenant($s['org'], fn () => business_today()->toDateString()));
        $this->assertSame('2026-03-10', business_today()->toDateString(), 'outside an organization the application time zone applies');

        $this->actingAsApi($s['owner'], $s['org']);
        $so = $this->api('POST', 'sales-orders', ['customer_id' => $clinic->id, 'warehouse_id' => $s['main']->id,
            'lines' => [['product_id' => $s['amox']->id, 'package_level_code' => 'pack', 'quantity' => '2', 'unit_price' => '1200']]], $this->idem())->assertCreated()->json('data');
        $this->assertSame('2026-03-11', substr($so['order_date'], 0, 10));
        $this->api('POST', "sales-orders/{$so['id']}/confirm")->assertOk();
        $this->api('POST', "sales-orders/{$so['id']}/deliveries", ['lines' => [['sales_order_line_id' => $so['lines'][0]['id'], 'quantity' => '2']]], $this->idem())->assertCreated();
        $invoice = $this->api('POST', "sales-orders/{$so['id']}/invoice", [], $this->idem())->assertCreated()->json('data');
        $this->assertSame('2026-03-11', substr($invoice['invoice_date'], 0, 10));

        // Reports count the sale on the local day; the local day's bounds are UTC instants.
        $this->assertSame(1, $this->api('GET', 'analytics/sales?from=2026-03-11&to=2026-03-11')->json('data.invoices'));
        $this->assertSame(0, $this->api('GET', 'analytics/sales?from=2026-03-10&to=2026-03-10')->json('data.invoices'));
        [$start, $end] = $this->inTenant($s['org'], fn () => [app(BusinessCalendar::class)->dayStart('2026-03-11'), app(BusinessCalendar::class)->dayEnd('2026-03-11')]);
        $this->assertSame(['2026-03-10 23:00:00', '2026-03-11 22:59:59'], [$start->toDateTimeString(), $end->toDateTimeString()]);

        // An organization can set its own time zone (e.g. a branch network in another zone).
        $this->api('PATCH', 'organization', ['timezone' => 'Not/AZone'])->assertUnprocessable()->assertJsonValidationErrors('timezone');
        $org = $this->api('PATCH', 'organization', ['timezone' => 'UTC', 'locale' => 'en_GB'])->assertOk()->json('data');
        $this->assertSame(['UTC', 'en_GB', 'NGN', 2, 'd/m/Y'], [$org['locale']['timezone'], $org['locale']['locale'], $org['locale']['currency_code'], $org['locale']['currency_minor_units'], $org['locale']['date_format']]);
        $this->assertSame('2026-03-10', $this->inTenant($s['org']->fresh(), fn () => app(BusinessCalendar::class)->today()->toDateString()));
    }

    public function test_money_is_rounded_to_the_currency_and_tax_inclusive_prices_post_correctly(): void
    {
        // Rwandan francs have no minor unit; the naira has two.
        $this->assertSame(['1000.0000', '180.0000', '1180.0000'], array_values(array_intersect_key(SalesPricing::amounts('3', '333.33', '0', '18', 'RWF'), array_flip(['subtotal', 'tax', 'total']))));
        $this->assertSame(['999.9900', '180.0000', '1179.9900'], array_values(array_intersect_key(SalesPricing::amounts('3', '333.33', '0', '18', 'NGN'), array_flip(['subtotal', 'tax', 'total']))));
        $this->assertSame('0.0000', Money::forCurrency('0.4999', 'UGX'));

        // Tax-inclusive: 118 including 18% with a 10% discount → total 106.20 = 100.00 − 10.00 + 16.20.
        $this->assertSame(['subtotal' => '100.0000', 'discount' => '10.0000', 'tax' => '16.2000', 'total' => '106.2000'], SalesPricing::amounts('1', '118', '10', '18', 'NGN', true));

        // End to end in a country configured for tax-inclusive shelf prices.
        $s = $this->stockedSupplier();
        $ng = Country::query()->find('NG');
        $ng->forceFill(['settings' => array_replace_recursive($ng->settings, ['tax' => ['prices_include_tax' => true]])])->save();
        $this->inTenant($s['org'], fn () => OrganizationProduct::query()->where('product_id', $s['amox']->id)->update(['tax_category_code' => 'VAT_STANDARD']));
        $clinic = $this->inTenant($s['org'], fn () => Customer::query()->create(['code' => 'CL1', 'name' => 'Synthetic Clinic', 'type' => 'clinic', 'currency_code' => 'NGN']));
        $so = $this->actingAsApi($s['owner'], $s['org'])->api('POST', 'sales-orders', ['customer_id' => $clinic->id, 'warehouse_id' => $s['main']->id,
            'lines' => [['product_id' => $s['amox']->id, 'package_level_code' => 'pack', 'quantity' => '1', 'unit_price' => '1075']]], $this->idem())->assertCreated()->json('data');
        $this->assertSame(['1000.0000', '75.0000', '1075.0000'], [$so['subtotal'], $so['tax_total'], $so['total']]);
    }

    public function test_values_are_formatted_for_the_locale(): void
    {
        [$owner, $org] = $this->organizationMember();
        $out = $this->inTenant($org, function () {
            $f = app(Localizer::class);

            return [$f->money('2400', 'NGN'), $f->money('1000', 'RWF'), $f->date('2026-03-11'), $f->number('1234567.891', 2)];
        });

        $this->assertStringContainsString('2,400.00', $out[0]);
        $this->assertStringContainsString('1,000', $out[1]);
        $this->assertStringNotContainsString('1,000.', $out[1], 'no decimals for francs');
        $this->assertSame(['11/03/2026', '1,234,567.89'], [$out[2], $out[3]]);
    }

    private function idem(): array
    {
        return ['Idempotency-Key' => 'lo-'.Str::lower(Str::random(16))];
    }
}

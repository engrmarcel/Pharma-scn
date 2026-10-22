<?php

namespace App\Domain\Analytics\Services;

use App\Domain\Analytics\Support\AnalyticsDb;
use App\Domain\Analytics\Support\Figures;
use App\Domain\Platform\Services\CurrencyConverter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * Platform view for platform staff (spec §19.1 subscription revenue, marketplace
 * transactions; §20 country-aware reports): recurring revenue from subscriptions,
 * organizations, marketplace value and fees, overall and per country. Totals across
 * organizations only; no tenant's own books.
 *
 * Money stays per currency. With a reporting currency, totals are also shown converted
 * at recorded exchange rates (rate, date and source listed); amounts without a rate are
 * listed as unconverted.
 */
class PlatformAnalytics
{
    private const PLACED = ['draft', 'pending_approval', 'cancelled', 'rejected'];

    public function __construct(private readonly CurrencyConverter $converter) {}

    public function build(string $from, string $to, ?string $reportingCurrency = null): array
    {
        Gate::authorize('platform.billing.manage');
        [$start, $end] = [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()];

        $current = AnalyticsDb::table('organization_subscriptions as s')->join('subscription_plans as p', 'p.id', '=', 's.plan_id')
            ->join('organizations as o', 'o.id', '=', 's.organization_id')
            ->whereNull('s.ended_at')->whereIn('s.status', ['active', 'trialing'])
            ->get(['s.status', 'o.country_code', 'p.code', 'p.name', 'p.billing_period', 'p.price', 'p.currency_code']);
        // Monthly recurring revenue: annual plans count one twelfth; custom-period plans are listed but not normalised.
        $monthly = fn ($r) => match ($r->billing_period) {
            'monthly' => Figures::dec($r->price),
            'annual' => bcdiv(Figures::dec($r->price), '12', 4),
            default => null,
        };
        $mrr = fn ($rows) => Figures::money($rows->where('status', 'active')->filter(fn ($r) => $monthly($r) !== null)
            ->map(fn ($r) => (object) ['currency_code' => $r->currency_code, 'amount' => $monthly($r)]));
        $orders = fn () => AnalyticsDb::table('marketplace_orders as m')->join('organizations as b', 'b.id', '=', 'm.buyer_organization_id')
            ->whereBetween('m.created_at', [$start, $end])->whereNotIn('m.status', self::PLACED);
        $convert = fn (array $money) => $reportingCurrency ? $this->converter->total($money, $reportingCurrency, $to) : null;

        $countries = AnalyticsDb::table('organizations as o')->join('countries as c', 'c.code', '=', 'o.country_code')
            ->groupBy('o.country_code', 'c.name', 'c.currency_code', 'c.is_active')->orderBy('o.country_code')
            ->selectRaw('o.country_code, c.name, c.currency_code, c.is_active, COUNT(*) as organizations,
                SUM(CASE WHEN o.verification_status = \'verified\' THEN 1 ELSE 0 END) as verified')->get();
        $byCountry = $countries->map(function ($c) use ($current, $mrr, $orders, $convert) {
            $value = Figures::money($orders()->where('b.country_code', $c->country_code)->groupBy('m.currency_code')
                ->selectRaw('m.currency_code, SUM(m.subtotal) as amount')->get());
            $rows = $current->where('country_code', $c->country_code);

            return ['country_code' => $c->country_code, 'name' => $c->name, 'is_active' => (bool) $c->is_active, 'local_currency' => $c->currency_code,
                'organizations' => (int) $c->organizations, 'verified' => (int) $c->verified,
                'active_subscriptions' => $rows->where('status', 'active')->count(), 'monthly_recurring_revenue' => $mrr($rows),
                'marketplace_value_by_buyer_country' => $value,
                'converted' => $convert($mrr($rows)) ? ['monthly_recurring_revenue' => $convert($mrr($rows)), 'marketplace_value' => $convert($value)] : null];
        })->values();

        $totalMrr = $mrr($current);
        $marketValue = Figures::money($orders()->groupBy('m.currency_code')->selectRaw('m.currency_code, SUM(m.subtotal) as amount')->get());

        return [
            'from' => $from, 'to' => $to, 'reporting_currency' => $reportingCurrency,
            'subscriptions' => [
                'by_status' => $current->countBy('status'),
                'by_plan' => $current->groupBy('code')->map(fn ($rows, $code) => ['plan' => $code, 'name' => $rows[0]->name, 'active' => $rows->where('status', 'active')->count(),
                    'trialing' => $rows->where('status', 'trialing')->count()])->values(),
                'monthly_recurring_revenue' => $totalMrr,
                'monthly_recurring_revenue_converted' => $convert($totalMrr),
                'custom_period_subscriptions' => $current->where('status', 'active')->filter(fn ($r) => $monthly($r) === null)->count(),
                'new_in_period' => AnalyticsDb::table('organization_subscriptions')->whereBetween('starts_at', [$start, $end])->count(),
                'cancelled_in_period' => AnalyticsDb::table('organization_subscriptions')->whereBetween('cancelled_at', [$start, $end])->count(),
                'basis' => 'Active subscriptions at list price (monthly, or annual ÷ 12). Trials are excluded. Discounts and invoicing are not modelled yet.',
            ],
            'organizations' => [
                'by_verification' => AnalyticsDb::table('organizations')->groupBy('verification_status')->selectRaw('verification_status, COUNT(*) as n')
                    ->pluck('n', 'verification_status')->map(fn ($n) => (int) $n),
                'registered_in_period' => AnalyticsDb::table('organizations')->whereBetween('created_at', [$start, $end])->count(),
            ],
            'marketplace' => [
                'orders' => $orders()->count(),
                'value' => $marketValue,
                'value_converted' => $convert($marketValue),
                'fees_accrued' => Figures::money(AnalyticsDb::table('marketplace_fees')->where('status', 'accrued')->whereBetween('created_at', [$start, $end])
                    ->groupBy('currency_code')->selectRaw('currency_code, SUM(amount) as amount')->get()),
            ],
            'by_country' => $byCountry,
            'conversion_basis' => $reportingCurrency ? "Converted to {$reportingCurrency} at the latest recorded rate on or before {$to}, for presentation only; amounts without a rate are listed as unconverted." : null,
        ];
    }
}

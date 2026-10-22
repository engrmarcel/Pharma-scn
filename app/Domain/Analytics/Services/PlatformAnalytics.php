<?php

namespace App\Domain\Analytics\Services;

use App\Domain\Analytics\Support\AnalyticsDb;
use App\Domain\Analytics\Support\Figures;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * Platform view for platform staff (spec §19.1 subscription revenue, marketplace
 * transactions): recurring revenue from subscriptions, organizations, marketplace
 * value and fees. Totals across organizations only; no tenant's own books.
 */
class PlatformAnalytics
{
    public function build(string $from, string $to): array
    {
        Gate::authorize('platform.billing.manage');
        [$start, $end] = [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()];

        $current = AnalyticsDb::table('organization_subscriptions as s')->join('subscription_plans as p', 'p.id', '=', 's.plan_id')
            ->whereNull('s.ended_at')->whereIn('s.status', ['active', 'trialing'])
            ->get(['s.status', 'p.code', 'p.name', 'p.billing_period', 'p.price', 'p.currency_code']);
        // Monthly recurring revenue: annual plans count one twelfth; custom-period plans are listed but not normalised.
        $monthly = fn ($r) => match ($r->billing_period) {
            'monthly' => Figures::dec($r->price),
            'annual' => bcdiv(Figures::dec($r->price), '12', 4),
            default => null,
        };
        $paying = $current->where('status', 'active');

        return [
            'from' => $from, 'to' => $to,
            'subscriptions' => [
                'by_status' => $current->countBy('status'),
                'by_plan' => $current->groupBy('code')->map(fn ($rows, $code) => ['plan' => $code, 'name' => $rows[0]->name, 'active' => $rows->where('status', 'active')->count(),
                    'trialing' => $rows->where('status', 'trialing')->count()])->values(),
                'monthly_recurring_revenue' => Figures::money($paying->filter(fn ($r) => $monthly($r) !== null)->map(fn ($r) => (object) ['currency_code' => $r->currency_code, 'amount' => $monthly($r)])),
                'custom_period_subscriptions' => $paying->filter(fn ($r) => $monthly($r) === null)->count(),
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
                'orders' => AnalyticsDb::table('marketplace_orders')->whereBetween('created_at', [$start, $end])->whereNotIn('status', ['draft', 'pending_approval', 'cancelled', 'rejected'])->count(),
                'value' => Figures::money(AnalyticsDb::table('marketplace_orders')->whereBetween('created_at', [$start, $end])
                    ->whereNotIn('status', ['draft', 'pending_approval', 'cancelled', 'rejected'])->groupBy('currency_code')->selectRaw('currency_code, SUM(subtotal) as amount')->get()),
                'fees_accrued' => Figures::money(AnalyticsDb::table('marketplace_fees')->where('status', 'accrued')->whereBetween('created_at', [$start, $end])
                    ->groupBy('currency_code')->selectRaw('currency_code, SUM(amount) as amount')->get()),
            ],
        ];
    }
}

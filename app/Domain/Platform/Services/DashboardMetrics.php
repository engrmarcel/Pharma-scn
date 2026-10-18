<?php

namespace App\Domain\Platform\Services;

use App\Domain\Billing\Services\EntitlementService;
use App\Domain\Inventory\Services\LocationAccess;
use App\Domain\Inventory\Services\StockQueries;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Platform\Models\Country;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Read-only figures for the web dashboard. Every card is computed only when
 * the module is usable for the organization (enabled and entitled) and the
 * member holds the matching view permission; stock figures honour the
 * member's warehouse scope. Amounts are in the organization's country
 * currency only, never summed across currencies.
 */
class DashboardMetrics
{
    private const EXPIRY_WINDOW_DAYS = 90;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly LocationAccess $locations,
        private readonly StockQueries $stock,
        private readonly EntitlementService $entitlements,
    ) {}

    /**
     * @return array{cards: list<array>, platform: list<array>, expiring: list<object>, trend: ?array, currency: ?string}
     */
    public function for(User $user): array
    {
        $organization = $this->tenant->organization();
        $currency = $organization ? Country::query()->find($organization->country_code)?->currency_code : null;

        return [
            'cards' => $organization ? $this->organizationCards($organization, $currency) : [],
            'platform' => $this->platformCards(),
            'expiring' => $organization && $this->usable($organization, 'inventory', 'pos') && Gate::allows('inventory.view') ? $this->expiringSoon() : [],
            'trend' => $organization ? $this->salesTrend($organization, $currency) : null,
            'currency' => $currency,
        ];
    }

    private function organizationCards(Organization $org, ?string $currency): array
    {
        $cards = [];
        $today = today()->toDateString();

        if ($this->usable($org, 'inventory', 'pos') && Gate::allows('inventory.view')) {
            $balances = fn () => $this->locations->scope(DB::table('inventory_balances as b')
                ->join('batches as bt', 'bt.id', '=', 'b.batch_id')
                ->where('b.organization_id', $org->id)->where('b.quantity', '>', 0), 'b.warehouse_id');

            $cards[] = $this->card('Products in stock', $balances()->where('b.stock_state', 'available')->distinct()->count('b.product_id'),
                'cube', 'blue', 'Products with available stock');
            $cards[] = $this->card('Expiring in '.self::EXPIRY_WINDOW_DAYS.' days', $balances()->whereBetween('bt.expiry_date', [$today, today()->addDays(self::EXPIRY_WINDOW_DAYS)->toDateString()])->distinct()->count('b.batch_id'),
                'clock', 'amber', 'Batches to sell first or return');
            $cards[] = $this->card('Expired on hand', $balances()->where('bt.expiry_date', '<', $today)->distinct()->count('b.batch_id'),
                'exclamation-triangle', 'rose', 'Batches to quarantine and write off');
            $cards[] = $this->card('Low stock items', $this->stock->lowStock()->count(), 'arrow-trending-down', 'violet', 'At or below reorder level');

            if (Gate::allows('inventory.valuation.view')) {
                $value = $balances()->where('b.stock_state', 'available')->whereNotNull('bt.unit_cost')
                    ->when($currency, fn ($q) => $q->where('bt.currency_code', $currency))
                    ->selectRaw('COALESCE(SUM(b.quantity * bt.unit_cost), 0) as v')->value('v');
                $cards[] = $this->card('Stock value', $value, 'banknotes', 'emerald', 'Available stock at batch cost', $currency, 2);
            }
        }

        if ($this->usable($org, 'procurement') && Gate::allows('procurement.view')) {
            $open = DB::table('purchase_orders')->where('organization_id', $org->id)->whereIn('status', ['pending_approval', 'approved', 'sent', 'partially_received']);
            $awaiting = (clone $open)->where('status', 'pending_approval')->count();
            $cards[] = $this->card('Open purchase orders', $open->count(), 'truck', 'sky', $awaiting.' awaiting approval');
        }

        if ($this->usable($org, 'sales') && Gate::allows('sales.view')) {
            $open = DB::table('sales_orders')->where('organization_id', $org->id)->whereIn('status', ['pending_approval', 'confirmed', 'partially_delivered']);
            $awaiting = (clone $open)->where('status', 'pending_approval')->count();
            $cards[] = $this->card('Open sales orders', $open->count(), 'clipboard-document-list', 'blue', $awaiting.' awaiting approval');

            $unpaid = DB::table('sales_invoices')->where('organization_id', $org->id)->where('status', 'issued')->where('payment_status', '!=', 'paid')
                ->when($currency, fn ($q) => $q->where('currency_code', $currency));
            $cards[] = $this->card('Receivables', (clone $unpaid)->selectRaw('COALESCE(SUM(total - amount_paid - amount_credited), 0) as v')->value('v'),
                'document-currency-dollar', 'gold', $unpaid->count().' unpaid invoices', $currency, 2);
        }

        if ($this->usable($org, 'pos') && (Gate::allows('pos.reports.view') || Gate::allows('pos.sell'))) {
            $mine = ! Gate::allows('pos.reports.view');
            $sales = DB::table('pos_sales')->where('organization_id', $org->id)->where('status', 'completed')
                ->whereBetween('completed_at', [today()->startOfDay(), today()->endOfDay()])
                ->when($mine, fn ($q) => $q->where('cashier_id', auth()->id()));
            $cards[] = $this->card($mine ? 'My POS sales today' : 'POS sales today', (clone $sales)->sum('total'),
                'shopping-cart', 'emerald', $sales->count().' completed sales', $currency, 2);
        }

        return $cards;
    }

    private function platformCards(): array
    {
        if (! Gate::allows('platform.organizations.view')) {
            return [];
        }

        $orgs = DB::table('organizations');

        return [
            $this->card('Organizations', (clone $orgs)->where('status', 'active')->count(), 'building-office-2', 'blue', 'Active on the network'),
            $this->card('Awaiting verification', (clone $orgs)->where('verification_status', 'pending_review')->count(), 'shield-check', 'amber', 'Submitted for platform review', href: route('admin.verifications')),
            $this->card('Verified', (clone $orgs)->where('verification_status', 'verified')->count(), 'check-badge', 'emerald', 'Platform verification complete'),
            $this->card('Suspended', (clone $orgs)->where('status', 'suspended')->count(), 'no-symbol', 'rose', 'Organizations on hold'),
        ];
    }

    /** The five batches with stock that expire soonest within the window (expired stock is a separate card). */
    private function expiringSoon(): array
    {
        $query = DB::table('inventory_balances as b')
            ->join('batches as bt', 'bt.id', '=', 'b.batch_id')
            ->join('products as p', 'p.id', '=', 'b.product_id')
            ->where('b.organization_id', $this->tenant->id())
            ->where('b.quantity', '>', 0)
            ->whereBetween('bt.expiry_date', [today()->toDateString(), today()->addDays(self::EXPIRY_WINDOW_DAYS)->toDateString()])
            ->groupBy('b.batch_id', 'bt.batch_number', 'bt.expiry_date', 'p.display_name')
            ->selectRaw('p.display_name as product_name, bt.batch_number, bt.expiry_date, SUM(b.quantity) as quantity')
            ->orderBy('bt.expiry_date')->limit(5);
        $this->locations->scope($query, 'b.warehouse_id');

        return $query->get()->all();
    }

    /** Invoiced sales over the last 7 days (back-office and POS), in the organization currency. */
    private function salesTrend(Organization $org, ?string $currency): ?array
    {
        $backOffice = $this->usable($org, 'sales') && Gate::allows('sales.view');
        $pos = $this->usable($org, 'pos') && Gate::allows('pos.reports.view');
        if ((! $backOffice && ! $pos) || $currency === null) {
            return null;
        }

        $from = today()->subDays(6);
        $totals = DB::table('sales_invoices')->where('organization_id', $org->id)->where('status', 'issued')
            ->where('currency_code', $currency)->where('invoice_date', '>=', $from->toDateString())
            ->when(! $backOffice, fn ($q) => $q->where('source', 'pos'))
            ->groupBy('invoice_date')->selectRaw('invoice_date, SUM(total) as total')
            ->pluck('total', 'invoice_date')->mapWithKeys(fn ($v, $k) => [substr((string) $k, 0, 10) => (float) $v]);

        $days = [];
        for ($d = $from->copy(); $d->lte(today()); $d->addDay()) {
            $days[] = ['label' => $d->format('D'), 'date' => $d->toDateString(), 'total' => $totals[$d->toDateString()] ?? 0.0];
        }
        $max = max(array_column($days, 'total')) ?: 1;

        return [
            'days' => array_map(fn ($day) => $day + ['height' => (int) round($day['total'] / $max * 100)], $days),
            'sum' => array_sum(array_column($days, 'total')),
            'scope' => $backOffice ? 'All invoices' : 'POS invoices',
        ];
    }

    private function usable(Organization $org, string ...$modules): bool
    {
        foreach ($modules as $module) {
            if ($org->hasModule($module) && $this->entitlements->allows($org, "module.{$module}")) {
                return true;
            }
        }

        return false;
    }

    private function card(string $label, mixed $value, string $icon, string $tone, string $hint, ?string $currency = null, int $decimals = 0, ?string $href = null): array
    {
        return compact('label', 'icon', 'tone', 'hint', 'currency', 'decimals', 'href') + ['value' => (float) $value];
    }
}

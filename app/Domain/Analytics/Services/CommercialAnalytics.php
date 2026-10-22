<?php

namespace App\Domain\Analytics\Services;

use App\Domain\Analytics\Support\AnalyticsDb;
use App\Domain\Analytics\Support\Figures;
use App\Support\BusinessCalendar;
use Illuminate\Support\Carbon;

/**
 * Sales, customer performance, purchases, supplier performance, POS and marketplace
 * analytics (spec §19.1). Money is reported per currency; issued invoices only
 * (void invoices excluded); credit notes are shown separately as credited.
 */
class CommercialAnalytics
{
    private const COMMITTED_PO = ['approved', 'sent', 'partially_received', 'received', 'closed'];

    private const NOT_PLACED = ['draft', 'pending_approval', 'cancelled', 'rejected'];

    public function __construct(private readonly AnalyticsScope $scope) {}

    public function sales(string $from, string $to, ?string $warehouseId = null): array
    {
        $this->scope->authorize('sales.view');
        [$start, $end] = $this->scope->period($from, $to);
        $org = $this->scope->organizationId();
        $warehouses = $this->scope->warehouses($warehouseId);
        $bucket = Figures::bucket($start, $end);
        $invoices = fn () => $this->scope->scope(AnalyticsDb::table('sales_invoices as i')->where('i.organization_id', $org)->where('i.status', 'issued')
            ->whereBetween('i.invoice_date', [$from, $to]), 'i.warehouse_id', $warehouses);

        $totals = $invoices()->groupBy('i.currency_code')->selectRaw('i.currency_code, COUNT(*) as invoices, SUM(i.total) as amount, SUM(i.amount_credited) as credited, SUM(i.tax_total) as tax')->get();
        $bySource = $invoices()->groupBy('i.source', 'i.currency_code')->selectRaw('i.source, i.currency_code, COUNT(*) as invoices, SUM(i.total) as amount')->get()
            ->groupBy('source')->map(fn ($rows, $source) => ['source' => $source, 'invoices' => (int) $rows->sum('invoices'), 'amount' => Figures::money($rows)])->values();
        $trend = $invoices()->groupByRaw(Figures::bucketSql($bucket, 'i.invoice_date').', i.currency_code')
            ->selectRaw(Figures::bucketSql($bucket, 'i.invoice_date').' as period, i.currency_code, COUNT(*) as invoices, SUM(i.total) as amount')->orderBy('period')->get()
            ->map(fn ($r) => ['period' => (string) $r->period, 'currency_code' => $r->currency_code, 'invoices' => (int) $r->invoices, 'amount' => Figures::dec($r->amount)]);
        $products = $invoices()->join('sales_invoice_lines as l', 'l.sales_invoice_id', '=', 'i.id')->join('products as p', 'p.id', '=', 'l.product_id')
            ->leftJoin('organization_products as op', fn ($j) => $j->on('op.product_id', '=', 'l.product_id')->on('op.organization_id', '=', 'i.organization_id'))
            ->groupBy('l.product_id', 'op.sku', 'p.display_name', 'i.currency_code')
            ->selectRaw('l.product_id, op.sku, p.display_name as product_name, i.currency_code, SUM(l.base_quantity) as quantity, SUM(l.line_total) as amount')
            ->orderByDesc('amount')->limit(10)->get()
            ->map(fn ($r) => ['product_id' => $r->product_id, 'sku' => $r->sku, 'product_name' => $r->product_name, 'currency_code' => $r->currency_code,
                'quantity' => Figures::dec($r->quantity), 'amount' => Figures::dec($r->amount)]);

        return [
            'from' => $from, 'to' => $to, 'warehouse_id' => $warehouseId, 'trend_bucket' => $bucket,
            'invoices' => (int) $totals->sum('invoices'),
            'revenue' => Figures::money($totals),
            'credited' => Figures::money($totals, 'credited'),
            'tax' => Figures::money($totals, 'tax'),
            'by_source' => $bySource,
            'trend' => $trend,
            'top_products' => $products,
            'customers' => $this->customers($org, $warehouses, $from, $to),
        ];
    }

    /** Customer performance: revenue in the period, and what each still owes (all periods). */
    private function customers(string $org, ?array $warehouses, string $from, string $to): array
    {
        $today = business_today()->toDateString();
        $base = fn () => $this->scope->scope(AnalyticsDb::table('sales_invoices as i')->where('i.organization_id', $org)->where('i.status', 'issued'), 'i.warehouse_id', $warehouses);

        $top = $base()->whereBetween('i.invoice_date', [$from, $to])->whereNotNull('i.customer_id')
            ->join('customers as c', 'c.id', '=', 'i.customer_id')
            ->groupBy('i.customer_id', 'c.name', 'c.type', 'i.currency_code')
            ->selectRaw('i.customer_id, c.name, c.type, i.currency_code, COUNT(*) as invoices, SUM(i.total - i.amount_credited) as amount')
            ->orderByDesc('amount')->limit(10)->get()
            ->map(fn ($r) => ['customer_id' => $r->customer_id, 'name' => $r->name, 'type' => $r->type, 'currency_code' => $r->currency_code,
                'invoices' => (int) $r->invoices, 'net_amount' => Figures::dec($r->amount)]);
        $owed = $base()->where('i.payment_status', '!=', 'paid')->groupBy('i.currency_code')
            ->selectRaw('i.currency_code, SUM(i.total - i.amount_paid - i.amount_credited) as amount,
                SUM(CASE WHEN i.due_date < ? THEN i.total - i.amount_paid - i.amount_credited ELSE 0 END) as overdue,
                COUNT(DISTINCT CASE WHEN i.due_date < ? THEN i.customer_id END) as overdue_customers', [$today, $today])->get();

        return ['top' => $top, 'receivables' => Figures::money($owed), 'overdue' => Figures::money($owed, 'overdue'),
            'customers_overdue' => (int) $owed->sum('overdue_customers'), 'active_customers' => (int) $base()
                ->whereBetween('i.invoice_date', [$from, $to])->whereNotNull('i.customer_id')->distinct()->count('i.customer_id')];
    }

    public function purchases(string $from, string $to, ?string $warehouseId = null): array
    {
        $this->scope->authorize('procurement.view');
        [$start, $end] = $this->scope->period($from, $to);
        $org = $this->scope->organizationId();
        $warehouses = $this->scope->warehouses($warehouseId);
        $orders = fn () => $this->scope->scope(AnalyticsDb::table('purchase_orders as o')->where('o.organization_id', $org)
            ->whereBetween('o.order_date', [$from, $to]), 'o.warehouse_id', $warehouses);

        $byStatus = $orders()->groupBy('o.status')->selectRaw('o.status, COUNT(*) as n')->pluck('n', 'status')->map(fn ($n) => (int) $n);
        $committed = $orders()->whereIn('o.status', self::COMMITTED_PO)->groupBy('o.currency_code')->selectRaw('o.currency_code, SUM(o.total) as amount')->get();

        // Supplier performance over committed orders: fill rate by quantity, on-time first receipt, lead time.
        $receipts = AnalyticsDb::table('goods_receipts')->where('organization_id', $org)->whereNotNull('purchase_order_id')
            ->groupBy('purchase_order_id')->selectRaw('purchase_order_id, MIN(received_at) as first_received_at');
        $lines = AnalyticsDb::table('purchase_order_lines')->groupBy('purchase_order_id')
            ->selectRaw('purchase_order_id, SUM(base_quantity) as ordered, SUM(LEAST(received_base_quantity, base_quantity)) as received');
        $suppliers = $orders()->whereIn('o.status', self::COMMITTED_PO)->join('suppliers as s', 's.id', '=', 'o.supplier_id')
            ->joinSub($lines, 'l', 'l.purchase_order_id', '=', 'o.id')->leftJoinSub($receipts, 'r', 'r.purchase_order_id', '=', 'o.id')
            ->get(['o.supplier_id', 's.name', 'o.currency_code', 'o.total', 'o.order_date', 'o.sent_at', 'o.expected_date', 'l.ordered', 'l.received', 'r.first_received_at'])
            ->groupBy('supplier_id')->map(function ($rows) {
                $received = $rows->whereNotNull('first_received_at');
                $timed = $received->whereNotNull('expected_date');
                $onTime = $timed->filter(fn ($r) => Carbon::parse($r->first_received_at)->toDateString() <= $r->expected_date)->count();
                $lead = $received->map(fn ($r) => Carbon::parse($r->sent_at ?? $r->order_date)->diffInDays(Carbon::parse($r->first_received_at)));
                $ordered = $rows->reduce(fn ($c, $r) => bcadd($c, Figures::dec($r->ordered), 4), '0');
                $got = $rows->reduce(fn ($c, $r) => bcadd($c, Figures::dec($r->received), 4), '0');

                return ['supplier_id' => $rows[0]->supplier_id, 'name' => $rows[0]->name, 'orders' => $rows->count(), 'spend' => Figures::money($rows, 'total'),
                    'fill_rate_percent' => bccomp($ordered, '0', 4) > 0 ? round((float) bcdiv(bcmul($got, '100', 4), $ordered, 4), 1) : null,
                    'on_time_percent' => Figures::percent($onTime, $timed->count()), 'orders_timed' => $timed->count(),
                    'average_lead_time_days' => $lead->isNotEmpty() ? round($lead->avg(), 1) : null];
            })->sortByDesc('orders')->values();

        return [
            'from' => $from, 'to' => $to, 'warehouse_id' => $warehouseId,
            'orders_by_status' => $byStatus,
            'committed_spend' => Figures::money($committed),
            'suppliers' => $suppliers,
            'definitions' => ['committed' => 'Orders approved or later ('.implode(', ', self::COMMITTED_PO).'), by order date.',
                'fill_rate' => 'Base units received (up to the quantity ordered) ÷ base units ordered.',
                'on_time' => 'First goods receipt on or before the expected date, for orders that have both.',
                'lead_time' => 'Days from sending (or the order date) to the first goods receipt.'],
        ];
    }

    public function pos(string $from, string $to, ?string $warehouseId = null): array
    {
        $this->scope->authorize('pos.reports.view');
        [$start, $end] = $this->scope->period($from, $to);
        $org = $this->scope->organizationId();
        $warehouses = $this->scope->warehouses($warehouseId);
        $sales = fn () => $this->scope->scope(AnalyticsDb::table('pos_sales as s')->where('s.organization_id', $org)->where('s.status', 'completed')
            ->whereBetween('s.completed_at', [$start, $end]), 's.warehouse_id', $warehouses);

        $totals = $sales()->groupBy('s.currency_code')->selectRaw('s.currency_code, COUNT(*) as sales, SUM(s.total) as amount, SUM(s.discount_total) as discount,
            SUM(CASE WHEN s.captured_offline = 1 THEN 1 ELSE 0 END) as offline')->get();
        $count = (int) $totals->sum('sales');
        $refunds = AnalyticsDb::table('pos_refunds as r')->join('pos_sales as s', 's.id', '=', 'r.pos_sale_id')->where('r.organization_id', $org)
            ->where('r.status', 'approved')->whereBetween('r.decided_at', [$start, $end])
            ->when($warehouses !== null, fn ($q) => $q->whereIn('s.warehouse_id', $warehouses))
            ->groupBy('s.currency_code')->selectRaw('s.currency_code, COUNT(*) as refunds, SUM(r.amount) as amount')->get();

        return [
            'from' => $from, 'to' => $to, 'warehouse_id' => $warehouseId,
            'sales' => $count,
            'gross' => Figures::money($totals),
            'discounts' => Figures::money($totals, 'discount'),
            'average_basket' => $totals->map(fn ($r) => ['currency_code' => $r->currency_code, 'amount' => bcdiv(Figures::dec($r->amount), (string) max(1, (int) $r->sales), 4)])->values(),
            'refunds' => ['count' => (int) $refunds->sum('refunds'), 'amount' => Figures::money($refunds)],
            'offline_percent' => Figures::percent((int) $totals->sum('offline'), $count),
            'by_terminal' => $sales()->join('pos_terminals as t', 't.id', '=', 's.pos_terminal_id')->groupBy('s.pos_terminal_id', 't.code', 't.name', 's.currency_code')
                ->selectRaw('s.pos_terminal_id, t.code, t.name, s.currency_code, COUNT(*) as sales, SUM(s.total) as amount')->orderByDesc('amount')->get()
                ->map(fn ($r) => ['terminal_id' => $r->pos_terminal_id, 'code' => $r->code, 'name' => $r->name, 'currency_code' => $r->currency_code, 'sales' => (int) $r->sales, 'amount' => Figures::dec($r->amount)]),
            'by_cashier' => $sales()->join('users as u', 'u.id', '=', 's.cashier_id')->groupBy('s.cashier_id', 'u.name', 's.currency_code')
                ->selectRaw('s.cashier_id, u.name, s.currency_code, COUNT(*) as sales, SUM(s.total) as amount')->orderByDesc('amount')->get()
                ->map(fn ($r) => ['cashier_id' => (int) $r->cashier_id, 'name' => $r->name, 'currency_code' => $r->currency_code, 'sales' => (int) $r->sales, 'amount' => Figures::dec($r->amount)]),
            // Local hour of the day (timestamps are UTC; African time zones have no daylight saving).
            // The offset is an integer computed here (not user input), inlined so SELECT and GROUP BY are the same expression.
            'by_hour' => $sales()->groupByRaw($hour = 'HOUR(DATE_ADD(s.completed_at, INTERVAL '.(int) now(app(BusinessCalendar::class)->timezone())->utcOffset().' MINUTE))')
                ->selectRaw("{$hour} as hour, COUNT(*) as sales")->orderBy('hour')->pluck('sales', 'hour')
                ->map(fn ($n) => (int) $n),
        ];
    }

    /** Marketplace transactions from both sides. Organization-wide: needs all locations. */
    public function marketplace(string $from, string $to): array
    {
        $this->scope->authorize('marketplace.browse', 'marketplace.orders.place', 'marketplace.orders.fulfil');
        $this->scope->requireAllLocations('marketplace');
        [$start, $end] = $this->scope->period($from, $to);
        $org = $this->scope->organizationId();

        $side = function (string $own, string $other) use ($org, $start, $end) {
            $orders = fn () => AnalyticsDb::table('marketplace_orders as o')->where("o.{$own}", $org)->whereBetween('o.created_at', [$start, $end]);

            return [
                'orders_by_status' => $orders()->groupBy('o.status')->selectRaw('o.status, COUNT(*) as n')->pluck('n', 'status')->map(fn ($n) => (int) $n),
                'value' => Figures::money($orders()->whereNotIn('o.status', self::NOT_PLACED)->groupBy('o.currency_code')
                    ->selectRaw('o.currency_code, SUM(o.subtotal) as amount')->get()),
                'top_partners' => $orders()->whereNotIn('o.status', self::NOT_PLACED)->join('organizations as p', 'p.id', '=', "o.{$other}")
                    ->groupBy("o.{$other}", 'p.name', 'o.currency_code')->selectRaw("o.{$other} as organization_id, p.name, o.currency_code, COUNT(*) as orders, SUM(o.subtotal) as amount")
                    ->orderByDesc('amount')->limit(10)->get()
                    ->map(fn ($r) => ['organization_id' => $r->organization_id, 'name' => $r->name, 'currency_code' => $r->currency_code, 'orders' => (int) $r->orders, 'amount' => Figures::dec($r->amount)]),
            ];
        };

        return [
            'from' => $from, 'to' => $to,
            'as_buyer' => $side('buyer_organization_id', 'supplier_organization_id'),
            'as_supplier' => $side('supplier_organization_id', 'buyer_organization_id') + [
                'fees' => Figures::money(AnalyticsDb::table('marketplace_fees')->where('supplier_organization_id', $org)->where('status', 'accrued')
                    ->whereBetween('created_at', [$start, $end])->groupBy('currency_code')->selectRaw('currency_code, SUM(amount) as amount')->get()),
            ],
            'relationships' => AnalyticsDb::table('trading_relationships')->where(fn ($q) => $q->where('buyer_organization_id', $org)->orWhere('supplier_organization_id', $org))
                ->groupBy('status')->selectRaw('status, COUNT(*) as n')->pluck('n', 'status')->map(fn ($n) => (int) $n),
            'definitions' => ['value' => 'Order subtotal of orders submitted to the supplier and not cancelled or rejected, by creation date.'],
        ];
    }
}

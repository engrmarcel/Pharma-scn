<?php

namespace App\Domain\Pos\Services;

use App\Support\Exceptions\BusinessRuleViolation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * POS reporting (spec §11.5) over completed sales in a date range.
 * Gross margin uses recorded batch costs; sales of uncosted stock are
 * reported separately rather than assumed.
 */
class PosReports
{
    public const REPORTS = ['summary', 'by-product', 'by-cashier', 'by-payment-method', 'by-terminal', 'refunds', 'shifts', 'batches'];

    public function __construct(private readonly TenantContext $tenant) {}

    public function run(string $report, string $from, string $to): array
    {
        $range = [$from.' 00:00:00', $to.' 23:59:59'];

        $data = match ($report) {
            'summary' => $this->summary($range),
            'by-product' => $this->sales($range)->join('products as p', 'p.id', '=', 'l.product_id')
                ->groupBy('l.product_id', 'p.display_name')
                ->selectRaw('l.product_id, p.display_name, SUM(l.base_quantity) as base_quantity, SUM(l.line_subtotal - l.line_discount) as net_sales, SUM(l.line_tax) as tax')
                ->orderByDesc('net_sales')->get(),
            'by-cashier' => $this->sales($range)->join('users as u', 'u.id', '=', 's.cashier_id')
                ->groupBy('s.cashier_id', 'u.name')->selectRaw('s.cashier_id, u.name, COUNT(DISTINCT s.id) as sales, SUM(l.line_total) as total')->get(),
            'by-terminal' => $this->sales($range)->join('pos_terminals as t', 't.id', '=', 's.pos_terminal_id')
                ->groupBy('s.pos_terminal_id', 't.code', 't.branch_id')->selectRaw('s.pos_terminal_id, t.code, t.branch_id, COUNT(DISTINCT s.id) as sales, SUM(l.line_total) as total')->get(),
            'by-payment-method' => DB::table('pos_payments as p')->join('pos_sales as s', 's.id', '=', 'p.pos_sale_id')
                ->where('s.organization_id', $this->tenant->id())->where('s.status', 'completed')->whereBetween('s.completed_at', $range)->where('p.status', 'confirmed')
                ->groupBy('p.method', 'p.channel')->selectRaw('p.method, p.channel, COUNT(*) as payments, SUM(p.amount) as amount')->get(),
            'refunds' => DB::table('pos_refunds')->where('organization_id', $this->tenant->id())->whereBetween('created_at', $range)
                ->get(['number', 'pos_sale_id', 'status', 'reason', 'refund_method', 'amount', 'requested_by', 'decided_by', 'created_at']),
            'shifts' => DB::table('pos_shifts as sh')->join('users as u', 'u.id', '=', 'sh.cashier_id')
                ->where('sh.organization_id', $this->tenant->id())->whereBetween('sh.opened_at', $range)
                ->get(['sh.number', 'u.name as cashier', 'sh.status', 'sh.opened_at', 'sh.closed_at', 'sh.opening_float', 'sh.expected_cash', 'sh.counted_cash', 'sh.variance', 'sh.reviewed_by']),
            'batches' => DB::table('pos_sale_allocations as a')->join('pos_sale_lines as l', 'l.id', '=', 'a.pos_sale_line_id')
                ->join('pos_sales as s', 's.id', '=', 'l.pos_sale_id')->join('batches as b', 'b.id', '=', 'a.batch_id')
                ->where('s.organization_id', $this->tenant->id())->where('s.status', 'completed')->whereBetween('s.completed_at', $range)
                ->groupBy('a.batch_id', 'b.batch_number', 'b.expiry_date', 'b.product_id')
                ->selectRaw('a.batch_id, b.batch_number, b.expiry_date, b.product_id, SUM(a.base_quantity) as base_quantity')->orderBy('b.expiry_date')->get(),
            default => throw new BusinessRuleViolation('report_unknown', 'Unknown report.', 404),
        };

        return ['report' => $report, 'from' => $from, 'to' => $to, 'rows' => $data];
    }

    private function summary(array $range): array
    {
        $sales = DB::table('pos_sales')->where('organization_id', $this->tenant->id())->where('status', 'completed')->whereBetween('completed_at', $range);
        $cost = DB::table('pos_sale_allocations as a')->join('pos_sale_lines as l', 'l.id', '=', 'a.pos_sale_line_id')->join('pos_sales as s', 's.id', '=', 'l.pos_sale_id')
            ->where('s.organization_id', $this->tenant->id())->where('s.status', 'completed')->whereBetween('s.completed_at', $range)
            ->selectRaw('COALESCE(SUM(CASE WHEN a.unit_cost IS NOT NULL THEN a.base_quantity * a.unit_cost END), 0) as cost,
                COALESCE(SUM(CASE WHEN a.unit_cost IS NULL THEN a.base_quantity END), 0) as uncosted_units')->first();

        $net = bcsub((string) (clone $sales)->sum('subtotal'), (string) (clone $sales)->sum('discount_total'), 4);
        $refunded = (string) DB::table('pos_refunds')->where('organization_id', $this->tenant->id())->where('status', 'approved')->whereBetween('decided_at', $range)->sum('amount');

        return [
            'sales_count' => (clone $sales)->count(),
            'gross_sales' => bcadd((string) (clone $sales)->sum('subtotal'), '0', 4),
            'discounts' => bcadd((string) (clone $sales)->sum('discount_total'), '0', 4),
            'net_sales' => $net,
            'tax' => bcadd((string) (clone $sales)->sum('tax_total'), '0', 4),
            'total_collected' => bcadd((string) (clone $sales)->sum('total'), '0', 4),
            'refunds' => bcadd($refunded, '0', 4),
            'cost_of_goods' => bcadd((string) $cost->cost, '0', 4),
            'gross_margin' => bcsub($net, (string) $cost->cost, 4),
            'uncosted_units' => bcadd((string) $cost->uncosted_units, '0', 4),
            'note' => 'Gross margin excludes refunds and counts only stock with a recorded batch cost.',
        ];
    }

    private function sales(array $range)
    {
        return DB::table('pos_sale_lines as l')->join('pos_sales as s', 's.id', '=', 'l.pos_sale_id')
            ->where('s.organization_id', $this->tenant->id())->where('s.status', 'completed')->whereBetween('s.completed_at', $range);
    }
}

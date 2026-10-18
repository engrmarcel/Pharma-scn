<?php

namespace App\Domain\Finance\Services;

use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Management reports from the journal. Not certified statutory statements.
 */
class FinanceReports
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ChartOfAccounts $chart,
    ) {}

    /** Per-account debit/credit totals up to a date; balanced by construction. */
    public function trialBalance(string $asOf): array
    {
        $this->chart->ensure();

        $rows = DB::table('accounts as a')
            ->leftJoin('journal_lines as l', 'l.account_id', '=', 'a.id')
            ->leftJoin('journal_entries as e', fn ($j) => $j->on('e.id', '=', 'l.journal_entry_id')->where('e.entry_date', '<=', $asOf))
            ->where('a.organization_id', $this->tenant->id())
            ->groupBy('a.id', 'a.code', 'a.name', 'a.type')
            ->orderBy('a.code')
            ->selectRaw('a.id, a.code, a.name, a.type,
                COALESCE(SUM(CASE WHEN e.id IS NOT NULL THEN l.debit END), 0) as debit,
                COALESCE(SUM(CASE WHEN e.id IS NOT NULL THEN l.credit END), 0) as credit')
            ->get()
            ->map(function ($r) {
                $net = bcsub((string) $r->debit, (string) $r->credit, 4);
                $debitNormal = in_array($r->type, ['asset', 'expense'], true);

                return [
                    'account_id' => $r->id, 'code' => $r->code, 'name' => $r->name, 'type' => $r->type,
                    'debit' => bcadd((string) $r->debit, '0', 4), 'credit' => bcadd((string) $r->credit, '0', 4),
                    'balance' => $debitNormal ? $net : bcmul($net, '-1', 4),
                ];
            });

        $debits = $rows->reduce(fn ($c, $r) => bcadd($c, $r['debit'], 4), '0');
        $credits = $rows->reduce(fn ($c, $r) => bcadd($c, $r['credit'], 4), '0');

        return ['as_of' => $asOf, 'accounts' => $rows->values()->all(), 'total_debit' => $debits, 'total_credit' => $credits, 'balanced' => bccomp($debits, $credits, 4) === 0];
    }

    public function accountLedger(string $accountId, string $from, string $to): array
    {
        $opening = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.account_id', $accountId)->where('e.organization_id', $this->tenant->id())->where('e.entry_date', '<', $from)
            ->selectRaw('COALESCE(SUM(l.debit - l.credit), 0) as net')->value('net');

        $running = bcadd((string) $opening, '0', 4);
        $lines = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.account_id', $accountId)->where('e.organization_id', $this->tenant->id())->whereBetween('e.entry_date', [$from, $to])
            ->orderBy('e.entry_date')->orderBy('e.number')
            ->get(['e.entry_date', 'e.number', 'e.description', 'e.source_type', 'e.source_id', 'l.debit', 'l.credit', 'l.memo', 'l.party_type', 'l.party_id'])
            ->map(function ($l) use (&$running) {
                $running = bcadd($running, bcsub((string) $l->debit, (string) $l->credit, 4), 4);

                return (array) $l + ['running_net_debit' => $running];
            });

        return ['account_id' => $accountId, 'from' => $from, 'to' => $to, 'opening_net_debit' => bcadd((string) $opening, '0', 4), 'lines' => $lines->all(), 'closing_net_debit' => $running];
    }

    public function profitAndLoss(string $from, string $to): array
    {
        $rows = $this->totalsByType($from, $to, ['revenue', 'expense']);
        $revenue = $rows->where('type', 'revenue')->reduce(fn ($c, $r) => bcadd($c, bcsub($r['credit'], $r['debit'], 4), 4), '0');
        $expenses = $rows->where('type', 'expense')->reduce(fn ($c, $r) => bcadd($c, bcsub($r['debit'], $r['credit'], 4), 4), '0');

        return ['from' => $from, 'to' => $to, 'lines' => $rows->values()->all(), 'revenue' => $revenue, 'expenses' => $expenses, 'net_income' => bcsub($revenue, $expenses, 4)];
    }

    public function balanceSheet(string $asOf): array
    {
        $tb = collect($this->trialBalance($asOf)['accounts']);
        $sum = fn (string $type) => $tb->where('type', $type)->reduce(fn ($c, $r) => bcadd($c, $r['balance'], 4), '0');
        $earnings = bcsub($sum('revenue'), $sum('expense'), 4);
        $equity = bcadd($sum('equity'), $earnings, 4);

        return [
            'as_of' => $asOf,
            'assets' => $sum('asset'),
            'liabilities' => $sum('liability'),
            'equity' => $equity,
            'current_period_earnings' => $earnings,
            'balanced' => bccomp($sum('asset'), bcadd($sum('liability'), $equity, 4), 4) === 0,
            'accounts' => $tb->whereIn('type', ['asset', 'liability', 'equity'])->values()->all(),
        ];
    }

    /** Open approved supplier invoices by days past due. */
    public function payablesAging(string $asOf): array
    {
        $rows = DB::table('supplier_invoices as i')->join('suppliers as s', 's.id', '=', 'i.supplier_id')
            ->where('i.organization_id', $this->tenant->id())->where('i.status', 'approved')->whereColumn('i.amount_paid', '<', 'i.total')
            ->get(['i.id', 'i.number', 'i.supplier_invoice_number', 'i.supplier_id', 's.name as supplier_name', 'i.due_date', 'i.total', 'i.amount_paid', 'i.currency_code']);

        $buckets = ['current' => '0', '1_30' => '0', '31_60' => '0', '61_90' => '0', 'over_90' => '0'];
        $items = $rows->map(function ($r) use ($asOf, &$buckets) {
            $open = bcsub((string) $r->total, (string) $r->amount_paid, 4);
            $days = $r->due_date ? (int) floor((strtotime($asOf) - strtotime($r->due_date)) / 86400) : 0;
            $bucket = match (true) {
                $days <= 0 => 'current', $days <= 30 => '1_30', $days <= 60 => '31_60', $days <= 90 => '61_90', default => 'over_90',
            };
            $buckets[$bucket] = bcadd($buckets[$bucket], $open, 4);

            return (array) $r + ['open_amount' => $open, 'days_past_due' => max(0, $days), 'bucket' => $bucket];
        });

        return ['as_of' => $asOf, 'buckets' => $buckets, 'invoices' => $items->values()->all()];
    }

    /** Open issued sales invoices by days past due. */
    public function receivablesAging(string $asOf): array
    {
        $rows = DB::table('sales_invoices as i')->join('customers as c', 'c.id', '=', 'i.customer_id')
            ->where('i.organization_id', $this->tenant->id())->where('i.status', 'issued')
            ->whereRaw('i.total - i.amount_paid - i.amount_credited > 0')
            ->get(['i.id', 'i.number', 'i.customer_id', 'c.name as customer_name', 'i.due_date', 'i.total', 'i.amount_paid', 'i.amount_credited', 'i.currency_code']);

        $buckets = ['current' => '0', '1_30' => '0', '31_60' => '0', '61_90' => '0', 'over_90' => '0'];
        $items = $rows->map(function ($r) use ($asOf, &$buckets) {
            $open = bcsub(bcsub((string) $r->total, (string) $r->amount_paid, 4), (string) $r->amount_credited, 4);
            $days = $r->due_date ? (int) floor((strtotime($asOf) - strtotime($r->due_date)) / 86400) : 0;
            $bucket = match (true) {
                $days <= 0 => 'current', $days <= 30 => '1_30', $days <= 60 => '31_60', $days <= 90 => '61_90', default => 'over_90',
            };
            $buckets[$bucket] = bcadd($buckets[$bucket], $open, 4);

            return (array) $r + ['open_amount' => $open, 'days_past_due' => max(0, $days), 'bucket' => $bucket];
        });

        return ['as_of' => $asOf, 'buckets' => $buckets, 'invoices' => $items->values()->all()];
    }

    /**
     * Customer statement from the receivable sub-ledger: invoices increase the
     * balance; payments and credit notes reduce it.
     */
    public function customerStatement(string $customerId, string $from, string $to): array
    {
        $arAccount = $this->chart->id('accounts_receivable');
        $base = fn () => DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('e.organization_id', $this->tenant->id())->where('l.account_id', $arAccount)
            ->where('l.party_type', 'customer')->where('l.party_id', $customerId);

        $opening = bcadd((string) $base()->where('e.entry_date', '<', $from)->selectRaw('COALESCE(SUM(l.debit - l.credit), 0) as b')->value('b'), '0', 4);
        $running = $opening;
        $lines = $base()->whereBetween('e.entry_date', [$from, $to])->orderBy('e.entry_date')->orderBy('e.number')
            ->get(['e.entry_date', 'e.number', 'e.description', 'e.source_type', 'e.source_id', 'l.debit', 'l.credit'])
            ->map(function ($l) use (&$running) {
                $running = bcadd($running, bcsub((string) $l->debit, (string) $l->credit, 4), 4);

                return (array) $l + ['balance' => $running];
            });

        return ['customer_id' => $customerId, 'from' => $from, 'to' => $to, 'opening_balance' => $opening, 'lines' => $lines->all(), 'closing_balance' => $running];
    }

    private function totalsByType(string $from, string $to, array $types)
    {
        return DB::table('accounts as a')
            ->join('journal_lines as l', 'l.account_id', '=', 'a.id')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('a.organization_id', $this->tenant->id())->whereIn('a.type', $types)->whereBetween('e.entry_date', [$from, $to])
            ->groupBy('a.id', 'a.code', 'a.name', 'a.type')->orderBy('a.code')
            ->selectRaw('a.code, a.name, a.type, SUM(l.debit) as debit, SUM(l.credit) as credit')
            ->get()->map(fn ($r) => ['code' => $r->code, 'name' => $r->name, 'type' => $r->type,
                'debit' => bcadd((string) $r->debit, '0', 4), 'credit' => bcadd((string) $r->credit, '0', 4)]);
    }
}

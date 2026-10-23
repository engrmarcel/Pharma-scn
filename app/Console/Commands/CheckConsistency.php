<?php

namespace App\Console\Commands;

use App\Domain\Traceability\Services\TraceChain;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Invariants that must hold whatever the load (Phase 10 acceptance: transactional consistency is
 * preserved). Read-only. Run after a load test, after a restore, or on a schedule; exits non-zero
 * when anything is broken, and lists what.
 *
 *   php artisan ops:consistency [--organization=ULID]
 */
class CheckConsistency extends Command
{
    protected $signature = 'ops:consistency {--organization= : Check one organization only}';

    protected $description = 'Check stock, reservation, ledger, POS and traceability invariants';

    public function handle(TraceChain $chain): int
    {
        $org = $this->option('organization');
        $scoped = fn ($query, string $column = 'organization_id') => $org ? $query->where($column, $org) : $query;
        $checks = [];

        // 1. Every balance equals the sum of its movements (the ledger is the source of truth).
        $checks['balances_match_movements'] = $scoped(DB::table('inventory_balances as b'), 'b.organization_id')
            ->leftJoinSub(DB::table('inventory_movements')->groupBy('warehouse_id', 'batch_id', 'stock_state')
                ->selectRaw('warehouse_id, batch_id, stock_state, SUM(quantity) as total'), 'm', fn ($j) => $j->on('m.warehouse_id', '=', 'b.warehouse_id')
                ->on('m.batch_id', '=', 'b.batch_id')->on('m.stock_state', '=', 'b.stock_state'))
            ->whereRaw('ABS(b.quantity - COALESCE(m.total, 0)) > 0.00005')->count();

        // 2. No negative stock, and never more reserved than held.
        $checks['negative_balances'] = $scoped(DB::table('inventory_balances'))->where('quantity', '<', 0)->count();
        $checks['over_reserved_balances'] = $scoped(DB::table('inventory_balances'))->whereColumn('reserved_quantity', '>', 'quantity')->count();

        // 3. Reserved quantity on each available balance equals its active reservations.
        $checks['reservations_match_balances'] = $scoped(DB::table('inventory_balances as b'), 'b.organization_id')->where('b.stock_state', 'available')
            ->leftJoinSub(DB::table('stock_reservations')->where('status', 'active')->whereNotNull('batch_id')->groupBy('warehouse_id', 'batch_id')
                ->selectRaw('warehouse_id, batch_id, SUM(quantity) as total'), 'r', fn ($j) => $j->on('r.warehouse_id', '=', 'b.warehouse_id')->on('r.batch_id', '=', 'b.batch_id'))
            ->whereRaw('ABS(b.reserved_quantity - COALESCE(r.total, 0)) > 0.00005')->count();

        // 4. Every journal entry balances, and its total is its debits.
        $checks['unbalanced_journal_entries'] = $scoped(DB::table('journal_entries as e'), 'e.organization_id')
            ->join('journal_lines as l', 'l.journal_entry_id', '=', 'e.id')->groupBy('e.id', 'e.total')
            ->havingRaw('ABS(SUM(l.debit) - SUM(l.credit)) > 0.00005 OR ABS(SUM(l.debit) - e.total) > 0.00005')->get(['e.id'])->count();

        // 5. A completed POS sale is fully paid by confirmed payments and has its invoice.
        $checks['pos_sales_not_settled'] = $scoped(DB::table('pos_sales as s'), 's.organization_id')->where('s.status', 'completed')
            ->leftJoinSub(DB::table('pos_payments')->where('status', 'confirmed')->groupBy('pos_sale_id')->selectRaw('pos_sale_id, SUM(amount) as paid'), 'p', 'p.pos_sale_id', '=', 's.id')
            ->where(fn ($q) => $q->whereNull('s.sales_invoice_id')->orWhereRaw('ABS(s.total - COALESCE(p.paid, 0)) > 0.00005'))->count();

        // 6. Document numbers are unique per organization (also enforced by unique indexes).
        $checks['duplicate_document_numbers'] = collect(['pos_sales', 'sales_invoices', 'journal_entries'])
            ->sum(fn ($table) => $scoped(DB::table($table))->groupBy('organization_id', 'number')->havingRaw('COUNT(*) > 1')->get(['organization_id'])->count());

        // 7. Each organization's traceability hash chain is intact.
        $orgs = $org ? [$org] : DB::table('trace_chain_heads')->pluck('organization_id')->all();
        $checks['broken_trace_chains'] = collect($orgs)->filter(fn ($id) => ! $chain->verify($id)['intact'])->count();

        $failed = array_filter($checks);
        $this->table(['Check', 'Problems'], collect($checks)->map(fn ($n, $name) => [str_replace('_', ' ', $name), $n === 0 ? 'none' : $n])->values()->all());
        if ($failed !== []) {
            $this->error('Consistency problems found: '.implode(', ', array_keys($failed)));

            return self::FAILURE;
        }
        $this->info('All invariants hold.');

        return self::SUCCESS;
    }
}

<?php

namespace App\Domain\Platform\Services;

use App\Domain\Traceability\Services\TraceChain;
use Illuminate\Support\Facades\DB;

/**
 * Invariants that must hold whatever the load or the incident (Phase 10): stock equals its ledger,
 * nothing is negative or over-reserved, reservations match, journals balance, completed sales are
 * settled, document numbers are unique and every traceability chain verifies. Read-only.
 * Runs on the default connection (the restore drill switches it to the restored copy).
 */
class ConsistencyChecker
{
    public function __construct(private readonly TraceChain $chain) {}

    /** @return array<string, int> problems found per check (0 = holds) */
    public function check(?string $organizationId = null): array
    {
        $scoped = fn ($query, string $column = 'organization_id') => $organizationId ? $query->where($column, $organizationId) : $query;
        $checks = [];

        $checks['balances_match_movements'] = $scoped(DB::table('inventory_balances as b'), 'b.organization_id')
            ->leftJoinSub(DB::table('inventory_movements')->groupBy('warehouse_id', 'batch_id', 'stock_state')
                ->selectRaw('warehouse_id, batch_id, stock_state, SUM(quantity) as total'), 'm', fn ($j) => $j->on('m.warehouse_id', '=', 'b.warehouse_id')
                ->on('m.batch_id', '=', 'b.batch_id')->on('m.stock_state', '=', 'b.stock_state'))
            ->whereRaw('ABS(b.quantity - COALESCE(m.total, 0)) > 0.00005')->count();

        $checks['negative_balances'] = $scoped(DB::table('inventory_balances'))->where('quantity', '<', 0)->count();
        $checks['over_reserved_balances'] = $scoped(DB::table('inventory_balances'))->whereColumn('reserved_quantity', '>', 'quantity')->count();

        $checks['reservations_match_balances'] = $scoped(DB::table('inventory_balances as b'), 'b.organization_id')->where('b.stock_state', 'available')
            ->leftJoinSub(DB::table('stock_reservations')->where('status', 'active')->whereNotNull('batch_id')->groupBy('warehouse_id', 'batch_id')
                ->selectRaw('warehouse_id, batch_id, SUM(quantity) as total'), 'r', fn ($j) => $j->on('r.warehouse_id', '=', 'b.warehouse_id')->on('r.batch_id', '=', 'b.batch_id'))
            ->whereRaw('ABS(b.reserved_quantity - COALESCE(r.total, 0)) > 0.00005')->count();

        $checks['unbalanced_journal_entries'] = $scoped(DB::table('journal_entries as e'), 'e.organization_id')
            ->join('journal_lines as l', 'l.journal_entry_id', '=', 'e.id')->groupBy('e.id', 'e.total')
            ->havingRaw('ABS(SUM(l.debit) - SUM(l.credit)) > 0.00005 OR ABS(SUM(l.debit) - e.total) > 0.00005')->get(['e.id'])->count();

        $checks['pos_sales_not_settled'] = $scoped(DB::table('pos_sales as s'), 's.organization_id')->where('s.status', 'completed')
            ->leftJoinSub(DB::table('pos_payments')->where('status', 'confirmed')->groupBy('pos_sale_id')->selectRaw('pos_sale_id, SUM(amount) as paid'), 'p', 'p.pos_sale_id', '=', 's.id')
            ->where(fn ($q) => $q->whereNull('s.sales_invoice_id')->orWhereRaw('ABS(s.total - COALESCE(p.paid, 0)) > 0.00005'))->count();

        $checks['duplicate_document_numbers'] = collect(['pos_sales', 'sales_invoices', 'journal_entries'])
            ->sum(fn ($table) => $scoped(DB::table($table))->groupBy('organization_id', 'number')->havingRaw('COUNT(*) > 1')->get(['organization_id'])->count());

        $organizations = $organizationId ? [$organizationId] : DB::table('trace_chain_heads')->pluck('organization_id')->all();
        $checks['broken_trace_chains'] = collect($organizations)->filter(fn ($id) => ! $this->chain->verify($id)['intact'])->count();

        return $checks;
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Inventory\Services\LocationAccess;
use App\Domain\Organizations\Models\Warehouse;
use App\Domain\Parties\Models\Customer;
use App\Domain\Platform\Services\AuditLogger;
use App\Domain\Pos\Models\PosPayment;
use App\Domain\Pos\Models\PosRefund;
use App\Domain\Pos\Models\PosSale;
use App\Domain\Pos\Models\PosShift;
use App\Domain\Pos\Models\PosTerminal;
use App\Domain\Pos\Services\PosCatalogue;
use App\Domain\Pos\Services\PosOfflineCatalogue;
use App\Domain\Pos\Services\PosRefundService;
use App\Domain\Pos\Services\PosReports;
use App\Domain\Pos\Services\PosSaleService;
use App\Domain\Pos\Services\PosSessionService;
use App\Domain\Pos\Services\ReceiptPresenter;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Exceptions\BusinessRuleViolation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class PosController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly LocationAccess $locations,
        private readonly PosSessionService $sessions,
        private readonly PosSaleService $sales,
    ) {}

    // ---- Terminals and shifts --------------------------------------------------

    public function terminals(): JsonResponse
    {
        Gate::authorize('pos.sell');

        return response()->json(['data' => $this->locations->scope(PosTerminal::query())->orderBy('code')->get()]);
    }

    public function storeTerminal(Request $request): JsonResponse
    {
        Gate::authorize('pos.terminals.manage');
        $data = $request->validate([
            'warehouse_id' => ['required', 'ulid', Rule::exists('warehouses', 'id')->where('organization_id', $this->tenant->id())],
            'code' => ['required', 'string', 'max:32', 'alpha_dash', Rule::unique('pos_terminals')->where('organization_id', $this->tenant->id())],
            'name' => ['required', 'string', 'max:255'],
            'device_identifier' => ['nullable', 'string', 'max:128'],
        ]);

        return response()->json(['data' => $this->sessions->registerTerminal(Warehouse::query()->findOrFail($data['warehouse_id']), $data['code'], $data['name'], $data['device_identifier'] ?? null)], 201);
    }

    public function updateTerminal(Request $request, string $id, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('pos.terminals.manage');
        $terminal = PosTerminal::query()->findOrFail($id);
        $data = $request->validate(['status' => ['required', Rule::in(['active', 'disabled'])]]);
        $terminal->forceFill($data)->save();
        $audit->record('pos_terminal.status_changed', $terminal, new: $data);

        return response()->json(['data' => $terminal]);
    }

    public function openShift(Request $request): JsonResponse
    {
        Gate::authorize('pos.sell');
        $data = $request->validate(['pos_terminal_id' => ['required', 'ulid'], 'opening_float' => ['required', 'decimal:0,4', 'min:0']]);
        $terminal = PosTerminal::query()->findOrFail($data['pos_terminal_id']);
        $this->locations->assertWarehouse($terminal->warehouse_id);

        return response()->json(['data' => $this->sessions->openShift($terminal, $request->user(), (string) $data['opening_float'])], 201);
    }

    /** Shifts for supervisors and reporting: newest first, within the member's locations. */
    public function shifts(Request $request): JsonResponse
    {
        abort_unless(Gate::any(['pos.shift.review', 'pos.reports.view']), 403);
        $f = $request->validate([
            'status' => ['nullable', Rule::in(['open', 'closed', 'reviewed'])],
            'pos_terminal_id' => ['nullable', 'ulid'],
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
        $terminals = $this->locations->scope(PosTerminal::query())->pluck('id');

        return response()->json(PosShift::query()->select('pos_shifts.*')
            ->addSelect(['cashier_name' => User::query()->select('name')->whereColumn('users.id', 'pos_shifts.cashier_id')])
            ->whereIn('pos_terminal_id', $terminals)
            ->when($f['status'] ?? null, fn ($q, $v) => $v === 'reviewed' ? $q->whereNotNull('reviewed_at') : $q->where('status', $v)->when($v === 'closed', fn ($c) => $c->whereNull('reviewed_at')))
            ->when($f['pos_terminal_id'] ?? null, fn ($q, $v) => $q->where('pos_terminal_id', $v))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where('opened_at', '>=', $v))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->where('opened_at', '<', Carbon::parse($v)->addDay()))
            ->latest('opened_at')->paginate(50));
    }

    /** Completed and other sales, for finding a sale to refund or review. Cashiers see their own. */
    public function sales(Request $request): JsonResponse
    {
        Gate::authorize('pos.sell');
        $f = $request->validate([
            'number' => ['nullable', 'string', 'max:32'],
            'status' => ['nullable', 'string', 'max:20'],
            'pos_shift_id' => ['nullable', 'ulid'],
        ]);
        $seesAll = Gate::any(['pos.sale.cancel', 'pos.refund.approve', 'pos.reports.view']);

        return response()->json($this->locations->scope(PosSale::query())
            ->when(! $seesAll, fn ($q) => $q->where('cashier_id', $request->user()->id))
            ->when($f['number'] ?? null, fn ($q, $v) => $q->where('number', strtoupper(trim($v))))
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['pos_shift_id'] ?? null, fn ($q, $v) => $q->where('pos_shift_id', $v))
            ->latest()->paginate(50, ['id', 'number', 'pos_shift_id', 'pos_terminal_id', 'cashier_id', 'customer_id', 'status', 'currency_code', 'total', 'completed_at', 'created_at']));
    }

    public function currentShift(Request $request): JsonResponse
    {
        Gate::authorize('pos.sell');
        $shift = PosShift::query()->where('cashier_id', $request->user()->id)->where('status', 'open')->first();

        return response()->json(['data' => $shift ? $shift->toArray() + ['drawer' => $this->sessions->drawer($shift)] : null]);
    }

    public function showShift(string $id): JsonResponse
    {
        $shift = PosShift::query()->with('cashMovements')->findOrFail($id);
        $this->authorizeShift($shift, 'pos.shift.review');

        return response()->json(['data' => $shift->toArray() + ['drawer' => $this->sessions->drawer($shift)]]);
    }

    public function cashMovement(Request $request, string $id): JsonResponse
    {
        Gate::authorize('pos.cash.manage');
        $data = $request->validate(['amount' => ['required', 'decimal:0,4', 'not_in:0'], 'reason' => ['required', 'string', 'max:255']]);

        return response()->json(['data' => $this->sessions->cashMovement(PosShift::query()->findOrFail($id), (string) $data['amount'], $data['reason'])], 201);
    }

    public function closeShift(Request $request, string $id): JsonResponse
    {
        $shift = PosShift::query()->findOrFail($id);
        $this->authorizeShift($shift, 'pos.cash.manage');
        $data = $request->validate(['counted_cash' => ['required', 'decimal:0,4', 'min:0'], 'notes' => ['nullable', 'string', 'max:2000']]);

        return response()->json(['data' => $this->sessions->closeShift($shift, (string) $data['counted_cash'], $data['notes'] ?? null)]);
    }

    public function reviewShift(Request $request, string $id): JsonResponse
    {
        Gate::authorize('pos.shift.review');
        $notes = $request->validate(['notes' => ['nullable', 'string', 'max:2000']])['notes'] ?? null;

        return response()->json(['data' => $this->sessions->reviewShift(PosShift::query()->findOrFail($id), $request->user(), $notes)]);
    }

    // ---- Product lookup ---------------------------------------------------------

    /** Name, SKU, GTIN or batch number search with sellable stock at the terminal's warehouse. */
    /** Bootstrap for the till app: POS permissions, the cashier's open shift and the active terminals. */
    public function me(Request $request): JsonResponse
    {
        Gate::authorize('pos.sell');
        $abilities = ['pos.sell', 'pos.dispense.prescription', 'pos.cash.manage', 'pos.sale.cancel', 'pos.refund.request', 'sales.price.override', 'sales.approve'];
        $shift = PosShift::query()->where('cashier_id', $request->user()->id)->where('status', 'open')->first();

        return response()->json(['data' => [
            'user' => $request->user()->only(['id', 'name', 'email']),
            'organization' => $this->tenant->organization()->only(['id', 'name', 'country_code']),
            'permissions' => array_values(array_filter($abilities, fn ($a) => Gate::allows($a))),
            'shift' => $shift ? $shift->only(['id', 'number', 'pos_terminal_id', 'opened_at', 'opening_float']) + ['drawer' => $this->sessions->drawer($shift)] : null,
            'terminals' => $this->locations->scope(PosTerminal::query())->where('status', 'active')->orderBy('code')->get(['id', 'code', 'name', 'warehouse_id']),
            'server_time' => now()->toIso8601String(),
        ]]);
    }

    /** Snapshot for selling offline at this terminal (spec §13.1). */
    public function offlineCatalogue(string $id, PosOfflineCatalogue $catalogue): JsonResponse
    {
        Gate::authorize('pos.sell');
        $terminal = $this->locations->scope(PosTerminal::query())->where('status', 'active')->findOrFail($id);

        return response()->json(['data' => $catalogue->snapshot($terminal)]);
    }

    public function lookup(Request $request, PosCatalogue $catalogue): JsonResponse
    {
        Gate::authorize('pos.sell');
        $data = $request->validate(['pos_terminal_id' => ['required', 'ulid'], 'q' => ['required', 'string', 'min:2', 'max:100']]);

        return response()->json(['data' => $catalogue->lookup(PosTerminal::query()->findOrFail($data['pos_terminal_id']), $data['q'])]);
    }

    // ---- Sales ------------------------------------------------------------------

    public function startSale(Request $request): JsonResponse
    {
        Gate::authorize('pos.sell');
        $data = $request->validate([
            'pos_shift_id' => ['required', 'ulid'],
            'client_transaction_id' => ['required', 'ulid'],
            'customer_id' => ['nullable', 'ulid', Rule::exists('customers', 'id')->where('organization_id', $this->tenant->id())],
        ]);

        $sale = $this->sales->start(PosShift::query()->findOrFail($data['pos_shift_id']), $request->user(), $data['client_transaction_id'],
            isset($data['customer_id']) ? Customer::query()->findOrFail($data['customer_id']) : null);

        return response()->json(['data' => $sale], $sale->wasRecentlyCreated ? 201 : 200);
    }

    public function showSale(string $id): JsonResponse
    {
        Gate::authorize('pos.sell');

        return response()->json(['data' => $this->ownSale($id)->load('lines.allocations', 'payments')]);
    }

    public function setLines(Request $request, string $id): JsonResponse
    {
        $sale = $this->ownSale($id);
        $data = $request->validate([
            'lines' => ['present', 'array', 'max:200'],
            'lines.*.product_id' => ['required', 'ulid'],
            'lines.*.quantity' => ['required', 'decimal:0,4', 'gt:0'],
            'lines.*.package_level_code' => ['nullable', 'string', 'max:32'],
            'lines.*.unit_price' => ['nullable', 'decimal:0,4', 'min:0'],
            'lines.*.discount_percent' => ['nullable', 'decimal:0,4', 'between:0,100'],
            'lines.*.batch_id' => ['nullable', 'ulid'],
            'lines.*.prescription' => ['nullable', 'array'],
            'lines.*.prescription.reference' => ['nullable', 'string', 'max:64'],
            'lines.*.prescription.prescriber_name' => ['nullable', 'string', 'max:255'],
            'lines.*.prescription.prescribed_on' => ['nullable', 'date', 'before_or_equal:today'],
        ]);

        return response()->json(['data' => $this->sales->setLines($sale, $data['lines'])]);
    }

    public function saleAction(Request $request, string $id, string $action): JsonResponse
    {
        $sale = $this->ownSale($id);

        return response()->json(['data' => match ($action) {
            'hold' => $this->sales->hold($sale),
            'checkout' => $this->sales->checkout($sale),
            'cancel' => $this->cancel($request, $sale),
        }]);
    }

    public function addPayment(Request $request, string $id): JsonResponse
    {
        $sale = $this->ownSale($id);
        $data = $request->validate([
            'method' => ['required', Rule::in(['cash', 'card', 'bank_transfer', 'mobile_money'])],
            'amount' => ['required', 'decimal:0,4', 'gt:0'],
            'tendered' => ['nullable', 'decimal:0,4', 'gt:0'],
            // Electronic: "manual_terminal" (standalone card/POS terminal) or a configured provider code.
            'channel' => ['required_unless:method,cash', 'nullable', 'string', 'max:32'],
            'approval_code' => ['nullable', 'string', 'max:64'],
        ]);

        $result = $this->sales->addPayment($sale, $data['method'], (string) $data['amount'], $data['method'] === 'cash' ? 'cash' : $data['channel'],
            isset($data['tendered']) ? (string) $data['tendered'] : null, $data['approval_code'] ?? null);

        return response()->json(['data' => $result], 201);
    }

    public function confirmPayment(Request $request, string $paymentId): JsonResponse
    {
        Gate::authorize('pos.payments.confirm');
        $payment = PosPayment::query()->findOrFail($paymentId);
        PosSale::query()->findOrFail($payment->pos_sale_id); // tenant check

        return response()->json(['data' => $this->sales->confirmManualPayment($payment, $request->user())]);
    }

    public function pendingPayments(): JsonResponse
    {
        Gate::authorize('pos.payments.confirm');
        $saleIds = PosSale::query()->where('status', 'awaiting_payment')->pluck('id');

        return response()->json(['data' => PosPayment::query()->whereIn('pos_sale_id', $saleIds)->where('status', 'pending')->where('channel', 'manual_terminal')->get()]);
    }

    public function receipt(string $id): JsonResponse
    {
        $sale = $this->ownSale($id)->load('lines.allocations', 'payments');
        abort_unless($sale->status === 'completed', 409, 'Receipts are available for completed sales only.');

        return response()->json(['data' => app(ReceiptPresenter::class)->present($sale)]);
    }

    // ---- Refunds ---------------------------------------------------------------

    public function requestRefund(Request $request, string $id, PosRefundService $refunds): JsonResponse
    {
        Gate::authorize('pos.refund.request');
        $sale = PosSale::query()->findOrFail($id);
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            'refund_method' => ['required', Rule::in(['cash', 'bank_transfer'])],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.pos_sale_line_id' => ['required', 'ulid'],
            'lines.*.quantity' => ['required', 'decimal:0,4', 'gt:0'],
            'lines.*.restock_state' => ['nullable', Rule::in(['quarantined', 'damaged', 'available'])],
        ]);

        return response()->json(['data' => $refunds->request($sale, $data['lines'], $data['reason'], $data['refund_method'])], 201);
    }

    public function refunds(Request $request): JsonResponse
    {
        Gate::authorize('pos.refund.approve');
        $status = $request->validate(['status' => ['nullable', Rule::in(['pending_approval', 'approved', 'rejected'])]])['status'] ?? 'pending_approval';

        return response()->json(PosRefund::query()->where('status', $status)->latest()->paginate(50));
    }

    public function decideRefund(Request $request, string $id, string $decision, PosRefundService $refunds): JsonResponse
    {
        Gate::authorize('pos.refund.approve');
        $notes = $request->validate(['notes' => ['nullable', 'string', 'max:2000']])['notes'] ?? null;

        return response()->json(['data' => $refunds->decide(PosRefund::query()->findOrFail($id), $decision === 'approve', $request->user(), $notes)]);
    }

    // ---- Reports ----------------------------------------------------------------

    public function report(Request $request, string $report): JsonResponse
    {
        Gate::authorize('pos.reports.view');
        $d = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);

        return response()->json(['data' => app(PosReports::class)->run($report, $d['from'] ?? business_today()->toDateString(), $d['to'] ?? business_today()->toDateString())]);
    }

    private function cancel(Request $request, PosSale $sale): PosSale
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'max:255']])['reason'];
        if ($sale->payments()->whereIn('status', ['confirmed', 'pending'])->exists()) {
            Gate::authorize('pos.sale.cancel');
        }

        return $this->sales->cancel($sale, $reason);
    }

    /** Cashiers act on their own sales; supervisors (pos.sale.cancel) may act on any. */
    private function ownSale(string $id): PosSale
    {
        Gate::authorize('pos.sell');
        $sale = PosSale::query()->findOrFail($id);
        if ($sale->cashier_id !== auth()->id() && ! Gate::allows('pos.sale.cancel')) {
            throw new BusinessRuleViolation('pos_sale_not_yours', 'This sale belongs to another cashier.', 403);
        }

        return $sale;
    }

    private function authorizeShift(PosShift $shift, string $supervisorAbility): void
    {
        if ($shift->cashier_id !== auth()->id()) {
            Gate::authorize($supervisorAbility);
        } else {
            Gate::authorize('pos.sell');
        }
    }
}

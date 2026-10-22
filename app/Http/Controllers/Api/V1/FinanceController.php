<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Finance\Models\Account;
use App\Domain\Finance\Models\FinancialPeriod;
use App\Domain\Finance\Models\JournalEntry;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Services\ChartOfAccounts;
use App\Domain\Finance\Services\FinanceReports;
use App\Domain\Finance\Services\GeneralLedger;
use App\Domain\Finance\Services\PaymentService;
use App\Domain\Parties\Models\Supplier;
use App\Domain\Platform\Services\AuditLogger;
use App\Http\Controllers\Controller;
use App\Support\Exceptions\BusinessRuleViolation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class FinanceController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
    ) {}

    public function accounts(ChartOfAccounts $chart): JsonResponse
    {
        Gate::authorize('finance.view');
        $chart->ensure();

        return response()->json(['data' => Account::query()->orderBy('code')->get(['id', 'code', 'name', 'type', 'system_key', 'parent_id', 'status'])]);
    }

    public function storeAccount(Request $request, ChartOfAccounts $chart): JsonResponse
    {
        Gate::authorize('finance.accounts.manage');
        $chart->ensure();
        $data = $request->validate([
            'code' => ['required', 'string', 'max:16', 'alpha_dash', Rule::unique('accounts')->where('organization_id', $this->tenant->id())],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(Account::TYPES)],
            'parent_id' => ['nullable', 'ulid', Rule::exists('accounts', 'id')->where('organization_id', $this->tenant->id())],
        ]);
        $account = Account::query()->create($data);
        $this->audit->record('account.created', $account, new: $data);

        return response()->json(['data' => $account], 201);
    }

    public function updateAccount(Request $request, string $id): JsonResponse
    {
        Gate::authorize('finance.accounts.manage');
        $account = Account::query()->findOrFail($id);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'code' => ['sometimes', 'string', 'max:16', 'alpha_dash', Rule::unique('accounts')->where('organization_id', $this->tenant->id())->ignore($account->id)],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);
        if (($data['status'] ?? null) === 'inactive' && $account->system_key !== null) {
            throw new BusinessRuleViolation('system_account_required', 'System accounts receive automatic postings and cannot be deactivated.', 409);
        }

        $before = $account->only(array_keys($data));
        $account->fill($data)->save();
        $this->audit->record('account.updated', $account, $before, $data);

        return response()->json(['data' => $account]);
    }

    public function periods(): JsonResponse
    {
        Gate::authorize('finance.view');

        return response()->json(['data' => FinancialPeriod::query()->orderByDesc('start_date')->get()]);
    }

    public function changePeriod(Request $request, string $id, string $action): JsonResponse
    {
        Gate::authorize('finance.periods.manage');
        $period = FinancialPeriod::query()->findOrFail($id);
        $reason = $request->validate(['reason' => [$action === 'reopen' ? 'required' : 'nullable', 'string', 'max:1000']])['reason'] ?? null;

        $period->forceFill($action === 'close'
            ? ['status' => 'closed', 'closed_by' => $request->user()->id, 'closed_at' => now()]
            : ['status' => 'open', 'closed_by' => null, 'closed_at' => null])->save();
        $this->audit->record("financial_period.{$action}d", $period, new: ['name' => $period->name, 'reason' => $reason]);

        return response()->json(['data' => $period]);
    }

    public function journalEntries(Request $request): JsonResponse
    {
        Gate::authorize('finance.view');
        $f = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'source_type' => ['nullable', 'string', 'max:40'], 'source_id' => ['nullable', 'ulid']]);

        return response()->json(JournalEntry::query()->with('lines')
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where('entry_date', '>=', $v))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->where('entry_date', '<=', $v))
            ->when($f['source_type'] ?? null, fn ($q, $v) => $q->where('source_type', $v))
            ->when($f['source_id'] ?? null, fn ($q, $v) => $q->where('source_id', $v))
            ->orderByDesc('entry_date')->orderByDesc('number')->paginate(50));
    }

    public function storeJournalEntry(Request $request, GeneralLedger $ledger): JsonResponse
    {
        Gate::authorize('finance.journal.post');
        $data = $request->validate([
            'entry_date' => ['required', 'date'],
            'description' => ['required', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:2', 'max:100'],
            'lines.*.account_id' => ['required', 'ulid', Rule::exists('accounts', 'id')->where('organization_id', $this->tenant->id())],
            'lines.*.debit' => ['nullable', 'decimal:0,4', 'min:0'],
            'lines.*.credit' => ['nullable', 'decimal:0,4', 'min:0'],
            'lines.*.memo' => ['nullable', 'string', 'max:255'],
        ]);

        $entry = $ledger->post($data['entry_date'], 'manual', null, $data['description'], $data['lines']);
        $this->audit->record('journal_entry.manual_posted', $entry, new: ['number' => $entry->number, 'total' => (string) $entry->total]);

        return response()->json(['data' => $entry], 201);
    }

    public function reverseJournalEntry(Request $request, string $id, GeneralLedger $ledger): JsonResponse
    {
        Gate::authorize('finance.journal.post');
        $data = $request->validate(['reason' => ['required', 'string', 'max:255'], 'entry_date' => ['nullable', 'date']]);

        return response()->json(['data' => $ledger->reverse(JournalEntry::query()->findOrFail($id), $data['reason'], $data['entry_date'] ?? null)], 201);
    }

    public function payments(Request $request): JsonResponse
    {
        Gate::authorize('finance.view');
        $f = $request->validate(['party_type' => ['nullable', Rule::in(['supplier', 'customer'])], 'party_id' => ['nullable', 'ulid']]);

        return response()->json(Payment::query()->with('allocations')
            ->when($f['party_type'] ?? null, fn ($q, $v) => $q->where('party_type', $v))
            ->when($f['party_id'] ?? null, fn ($q, $v) => $q->where('party_id', $v))
            ->latest('payment_date')->paginate(50));
    }

    public function paySupplier(Request $request, PaymentService $payments): JsonResponse
    {
        Gate::authorize('finance.payments.record');
        $data = $request->validate([
            'supplier_id' => ['required', 'ulid', Rule::exists('suppliers', 'id')->where('organization_id', $this->tenant->id())],
            'amount' => ['required', 'decimal:0,4', 'gt:0'],
            'method' => ['required', Rule::in(Payment::METHODS)],
            'payment_date' => ['required', 'date', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:64'],
            'account_id' => ['nullable', 'ulid', Rule::exists('accounts', 'id')->where('organization_id', $this->tenant->id())->where('type', 'asset')],
            'notes' => ['nullable', 'string', 'max:2000'],
            'allocations' => ['array'],
            'allocations.*.supplier_invoice_id' => ['required', 'ulid'],
            'allocations.*.amount' => ['required', 'decimal:0,4', 'gt:0'],
        ]);

        $payment = $payments->paySupplier(Supplier::query()->findOrFail($data['supplier_id']), (string) $data['amount'], $data['method'], $data['payment_date'],
            collect($data['allocations'] ?? [])->pluck('amount', 'supplier_invoice_id')->all(), $data['reference'] ?? null, $data['account_id'] ?? null, $data['notes'] ?? null);

        return response()->json(['data' => $payment], 201);
    }

    public function allocatePayment(Request $request, string $id, PaymentService $payments): JsonResponse
    {
        Gate::authorize('finance.payments.record');
        $data = $request->validate(['allocations' => ['required', 'array', 'min:1'], 'allocations.*.supplier_invoice_id' => ['required', 'ulid'], 'allocations.*.amount' => ['required', 'decimal:0,4', 'gt:0']]);

        return response()->json(['data' => $payments->allocate(Payment::query()->findOrFail($id), collect($data['allocations'])->pluck('amount', 'supplier_invoice_id')->all())]);
    }

    public function report(Request $request, string $report, FinanceReports $reports): JsonResponse
    {
        Gate::authorize('finance.view');
        $today = business_today()->toDateString();
        $d = $request->validate([
            'as_of' => ['nullable', 'date'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'],
            'account_id' => [$report === 'account-ledger' ? 'required' : 'nullable', 'ulid', Rule::exists('accounts', 'id')->where('organization_id', $this->tenant->id())],
        ]);
        $from = $d['from'] ?? business_today()->startOfMonth()->toDateString();
        $to = $d['to'] ?? $today;

        return response()->json(['data' => match ($report) {
            'trial-balance' => $reports->trialBalance($d['as_of'] ?? $today),
            'balance-sheet' => $reports->balanceSheet($d['as_of'] ?? $today),
            'profit-and-loss' => $reports->profitAndLoss($from, $to),
            'account-ledger' => $reports->accountLedger($d['account_id'], $from, $to),
            'payables-aging' => $reports->payablesAging($d['as_of'] ?? $today),
        }, 'meta' => ['notice' => 'Management report from the operational ledger. Not a certified statutory statement.']]);
    }
}

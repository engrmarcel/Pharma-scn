<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Services\FinanceReports;
use App\Domain\Finance\Services\PaymentService;
use App\Domain\Inventory\Services\LocationAccess;
use App\Domain\Organizations\Models\Warehouse;
use App\Domain\Parties\Models\Customer;
use App\Domain\Platform\Services\AuditLogger;
use App\Domain\Sales\Models\CreditNote;
use App\Domain\Sales\Models\Delivery;
use App\Domain\Sales\Models\PriceList;
use App\Domain\Sales\Models\Quotation;
use App\Domain\Sales\Models\SalesInvoice;
use App\Domain\Sales\Models\SalesOrder;
use App\Domain\Sales\Services\FulfilmentService;
use App\Domain\Sales\Services\SalesOrderService;
use App\Domain\Sales\Services\SalesPolicy;
use App\Http\Controllers\Controller;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SalesController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly LocationAccess $locations,
        private readonly SalesOrderService $orders,
        private readonly FulfilmentService $fulfilment,
    ) {}

    // ---- Price lists ----------------------------------------------------------

    public function priceLists(): JsonResponse
    {
        Gate::authorize('sales.view');

        return response()->json(['data' => PriceList::query()->with('items')->orderBy('code')->get()]);
    }

    public function storePriceList(Request $request, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('sales.price_lists.manage');
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32', 'alpha_dash', Rule::unique('price_lists')->where('organization_id', $this->tenant->id())],
            'name' => ['required', 'string', 'max:255'],
            'branch_id' => ['nullable', 'ulid', $this->own('branches')],
            'is_default' => ['sometimes', 'boolean'],
        ] + $this->priceItemRules());

        $list = DB::transaction(function () use ($data) {
            if ($data['is_default'] ?? false) {
                PriceList::query()->update(['is_default' => false]);
            }
            $list = PriceList::query()->create(collect($data)->except('items')->all() + ['currency_code' => $this->tenant->organization()->country->currency_code]);
            $list->items()->createMany(array_map(fn ($i) => $i + ['minimum_quantity' => $i['minimum_quantity'] ?? 1], $data['items'] ?? []));

            return $list;
        });
        $audit->record('price_list.created', $list, new: ['code' => $list->code, 'items' => count($data['items'] ?? [])]);

        return response()->json(['data' => $list->load('items')], 201);
    }

    public function replacePriceListItems(Request $request, string $id, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('sales.price_lists.manage');
        $list = PriceList::query()->findOrFail($id);
        $data = $request->validate($this->priceItemRules(required: true));

        DB::transaction(function () use ($list, $data) {
            $list->items()->delete();
            $list->items()->createMany(array_map(fn ($i) => $i + ['minimum_quantity' => $i['minimum_quantity'] ?? 1], $data['items']));
        });
        $audit->record('price_list.items_replaced', $list, new: ['items' => count($data['items'])]);

        return response()->json(['data' => $list->load('items')]);
    }

    // ---- Quotations and orders ------------------------------------------------

    public function quotations(): JsonResponse
    {
        Gate::authorize('sales.view');

        return response()->json($this->locations->scope(Quotation::query()->with('lines'))->latest()->paginate(50));
    }

    public function storeQuotation(Request $request): JsonResponse
    {
        Gate::authorize('sales.quote');
        $data = $request->validate($this->documentRules() + ['valid_until' => ['nullable', 'date', 'after_or_equal:today']]);
        $this->locations->assertWarehouse($data['warehouse_id']);

        return response()->json(['data' => $this->orders->createQuotation(Customer::query()->findOrFail($data['customer_id']), Warehouse::query()->findOrFail($data['warehouse_id']),
            $data['lines'], $data['valid_until'] ?? null, $data['notes'] ?? null)], 201);
    }

    public function convertQuotation(string $id): JsonResponse
    {
        Gate::authorize('sales.order');

        return response()->json(['data' => $this->orders->convertQuotation(Quotation::query()->findOrFail($id))], 201);
    }

    public function salesOrders(Request $request): JsonResponse
    {
        Gate::authorize('sales.view');
        $f = $request->validate(['status' => ['nullable', 'string', 'max:24'], 'customer_id' => ['nullable', 'ulid']]);

        return response()->json($this->locations->scope(SalesOrder::query()->with('lines'))
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['customer_id'] ?? null, fn ($q, $v) => $q->where('customer_id', $v))
            ->latest()->paginate(50));
    }

    public function showSalesOrder(string $id): JsonResponse
    {
        Gate::authorize('sales.view');
        $order = SalesOrder::query()->with('lines')->findOrFail($id);
        abort_unless($this->locations->canAccessWarehouse($order->warehouse_id), 404);

        return response()->json(['data' => $order->toArray() + [
            'deliveries' => Delivery::query()->with('lines')->where('sales_order_id', $order->id)->get(),
            'invoices' => SalesInvoice::query()->where('sales_order_id', $order->id)->get(['id', 'number', 'total', 'payment_status']),
        ]]);
    }

    public function storeSalesOrder(Request $request): JsonResponse
    {
        Gate::authorize('sales.order');
        $data = $request->validate($this->documentRules() + ['requested_date' => ['nullable', 'date']]);
        $this->locations->assertWarehouse($data['warehouse_id']);

        return response()->json(['data' => $this->orders->createOrder(Customer::query()->findOrFail($data['customer_id']), Warehouse::query()->findOrFail($data['warehouse_id']),
            $data['lines'], $data['requested_date'] ?? null, $data['notes'] ?? null)], 201);
    }

    public function updateSalesOrder(Request $request, string $id): JsonResponse
    {
        Gate::authorize('sales.order');
        $data = $request->validate(array_intersect_key($this->documentRules(), array_flip(['lines', 'lines.*.product_id', 'lines.*.quantity', 'lines.*.package_level_code', 'lines.*.unit_price', 'lines.*.discount_percent'])));

        return response()->json(['data' => $this->orders->updateOrder(SalesOrder::query()->findOrFail($id), $data['lines'])]);
    }

    public function salesOrderAction(Request $request, string $id, string $action): JsonResponse
    {
        $order = SalesOrder::query()->findOrFail($id);
        $this->locations->assertWarehouse($order->warehouse_id);
        $reason = fn () => $request->validate(['reason' => ['required', 'string', 'max:2000']])['reason'];

        $result = match ($action) {
            'confirm' => $this->authorized('sales.order', fn () => $this->orders->confirm($order)),
            'approve' => $this->authorized('sales.approve', fn () => $this->orders->decide($order, true, $request->user())),
            'reject' => $this->authorized('sales.approve', fn () => $this->orders->decide($order, false, $request->user(), $reason())),
            'cancel' => $this->authorized('sales.order', fn () => $this->orders->cancel($order, $reason())),
            'close' => $this->authorized('sales.order', fn () => $this->orders->close($order, $reason())),
        };

        return response()->json(['data' => $result]);
    }

    public function deliver(Request $request, string $id): JsonResponse
    {
        Gate::authorize('sales.deliver');
        $order = SalesOrder::query()->findOrFail($id);
        $this->locations->assertWarehouse($order->warehouse_id);
        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.sales_order_line_id' => ['required', 'ulid'],
            'lines.*.quantity' => ['required', 'decimal:0,4', 'gt:0'],
        ]);

        return response()->json(['data' => $this->fulfilment->deliver($order, $data['lines'], $data['notes'] ?? null)], 201);
    }

    public function invoiceOrder(string $id): JsonResponse
    {
        Gate::authorize('sales.invoice');
        $order = SalesOrder::query()->findOrFail($id);
        $this->locations->assertWarehouse($order->warehouse_id);

        return response()->json(['data' => $this->fulfilment->invoiceOrder($order)], 201);
    }

    // ---- Invoices, credit notes, payments --------------------------------------

    public function invoices(Request $request): JsonResponse
    {
        Gate::authorize('sales.view');
        $f = $request->validate(['customer_id' => ['nullable', 'ulid'], 'payment_status' => ['nullable', Rule::in(['unpaid', 'partial', 'paid'])]]);

        return response()->json(SalesInvoice::query()->with('lines')
            ->when($f['customer_id'] ?? null, fn ($q, $v) => $q->where('customer_id', $v))
            ->when($f['payment_status'] ?? null, fn ($q, $v) => $q->where('payment_status', $v))
            ->latest('invoice_date')->paginate(50));
    }

    public function showInvoice(string $id): JsonResponse
    {
        Gate::authorize('sales.view');
        $invoice = SalesInvoice::query()->with('lines')->findOrFail($id);

        return response()->json(['data' => $invoice->toArray() + [
            'open_balance' => $invoice->openBalance(),
            'credit_notes' => CreditNote::query()->with('lines')->where('sales_invoice_id', $invoice->id)->get(),
        ]]);
    }

    public function storeCreditNote(Request $request, string $invoiceId): JsonResponse
    {
        Gate::authorize('sales.credit');
        $invoice = SalesInvoice::query()->findOrFail($invoiceId);
        $data = $request->validate([
            'reason_code' => ['required', Rule::in(['return', 'price_adjustment', 'damaged_in_transit', 'other'])],
            'notes' => ['nullable', 'required_if:reason_code,other', 'string', 'max:2000'],
            'return_warehouse_id' => ['nullable', 'ulid', $this->own('warehouses')],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.sales_invoice_line_id' => ['required', 'ulid'],
            'lines.*.quantity' => ['required', 'decimal:0,4', 'gt:0'],
            'lines.*.batch_id' => ['nullable', 'ulid'],
            'lines.*.restock_state' => ['nullable', Rule::in(['quarantined', 'damaged', 'available'])],
        ]);
        if (isset($data['return_warehouse_id'])) {
            $this->locations->assertWarehouse($data['return_warehouse_id']);
        }

        return response()->json(['data' => $this->fulfilment->creditNote($invoice, $data['reason_code'], $data['lines'], $data['return_warehouse_id'] ?? null, $data['notes'] ?? null)], 201);
    }

    public function receivePayment(Request $request, PaymentService $payments): JsonResponse
    {
        Gate::authorize('finance.payments.record');
        $data = $request->validate([
            'customer_id' => ['required', 'ulid', $this->own('customers')],
            'amount' => ['required', 'decimal:0,4', 'gt:0'],
            'method' => ['required', Rule::in(Payment::METHODS)],
            'payment_date' => ['required', 'date', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:64'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'allocations' => ['array'],
            'allocations.*.sales_invoice_id' => ['required', 'ulid'],
            'allocations.*.amount' => ['required', 'decimal:0,4', 'gt:0'],
        ]);

        return response()->json(['data' => $payments->receiveFromCustomer(Customer::query()->findOrFail($data['customer_id']), (string) $data['amount'], $data['method'],
            $data['payment_date'], collect($data['allocations'] ?? [])->pluck('amount', 'sales_invoice_id')->all(), $data['reference'] ?? null, null, $data['notes'] ?? null)], 201);
    }

    public function customerStatement(Request $request, string $customerId, FinanceReports $reports): JsonResponse
    {
        Gate::authorize('sales.view');
        $customer = Customer::query()->findOrFail($customerId);
        $d = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);

        return response()->json(['data' => $reports->customerStatement($customer->id, $d['from'] ?? today()->subMonths(3)->toDateString(), $d['to'] ?? today()->toDateString())]);
    }

    public function receivablesAging(Request $request, FinanceReports $reports): JsonResponse
    {
        Gate::authorize('sales.view');

        return response()->json(['data' => $reports->receivablesAging($request->validate(['as_of' => ['nullable', 'date']])['as_of'] ?? today()->toDateString())]);
    }

    public function settings(SalesPolicy $policy): JsonResponse
    {
        Gate::authorize('sales.view');

        return response()->json(['data' => $policy->settings()]);
    }

    public function updateSettings(Request $request, SalesPolicy $policy, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('sales.settings.manage');
        $data = $request->validate(['max_discount_percent' => ['sometimes', 'decimal:0,4', 'between:0,100'], 'allow_self_approval' => ['sometimes', 'boolean']]);

        $organization = $this->tenant->organization();
        $before = $policy->settings();
        $settings = $organization->settings ?? [];
        foreach ($data as $key => $value) {
            data_set($settings, "sales.{$key}", $value);
        }
        $organization->forceFill(['settings' => $settings])->save();
        $audit->record('sales.settings_updated', $organization, $before, $data);

        return response()->json(['data' => $policy->settings()]);
    }

    private function documentRules(): array
    {
        return [
            'customer_id' => ['required', 'ulid', $this->own('customers')],
            'warehouse_id' => ['required', 'ulid', $this->own('warehouses')],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.product_id' => ['required', 'ulid'],
            'lines.*.quantity' => ['required', 'decimal:0,4', 'gt:0'],
            'lines.*.package_level_code' => ['nullable', 'string', 'max:32'],
            'lines.*.unit_price' => ['nullable', 'decimal:0,4', 'min:0'],
            'lines.*.discount_percent' => ['nullable', 'decimal:0,4', 'between:0,100'],
        ];
    }

    private function priceItemRules(bool $required = false): array
    {
        return [
            'items' => [$required ? 'present' : 'sometimes', 'array', 'max:5000'],
            'items.*.product_id' => ['required', 'ulid', 'exists:products,id'],
            'items.*.package_level_code' => ['required', 'string', 'max:32'],
            'items.*.unit_price' => ['required', 'decimal:0,4', 'min:0'],
            'items.*.minimum_quantity' => ['nullable', 'decimal:0,4', 'gt:0'],
            'items.*.valid_from' => ['nullable', 'date'],
            'items.*.valid_to' => ['nullable', 'date'],
        ];
    }

    private function own(string $table)
    {
        return Rule::exists($table, 'id')->where('organization_id', $this->tenant->id());
    }

    private function authorized(string $ability, callable $action): mixed
    {
        Gate::authorize($ability);

        return $action();
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Inventory\Services\LocationAccess;
use App\Domain\Inventory\Services\StockQueries;
use App\Domain\Organizations\Models\Warehouse;
use App\Domain\Parties\Models\Supplier;
use App\Domain\Platform\Services\AuditLogger;
use App\Domain\Procurement\Models\GoodsReceipt;
use App\Domain\Procurement\Models\PurchaseOrder;
use App\Domain\Procurement\Models\PurchaseRequisition;
use App\Domain\Procurement\Models\SupplierInvoice;
use App\Domain\Procurement\Models\SupplierPriceListItem;
use App\Domain\Procurement\Models\SupplierReturn;
use App\Domain\Procurement\Services\GoodsReceiptService;
use App\Domain\Procurement\Services\ProcurementPolicy;
use App\Domain\Procurement\Services\PurchaseOrderService;
use App\Domain\Procurement\Services\SupplierInvoiceService;
use App\Domain\Procurement\Services\SupplierReturnService;
use App\Http\Controllers\Controller;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ProcurementController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly LocationAccess $locations,
        private readonly PurchaseOrderService $orders,
    ) {}

    // ---- Requisitions -------------------------------------------------------

    public function requisitions(Request $request): JsonResponse
    {
        Gate::authorize('procurement.view');
        $status = $request->validate(['status' => ['nullable', 'string', 'max:20']])['status'] ?? null;

        return response()->json($this->locations->scope(PurchaseRequisition::query()->with('lines'))
            ->when($status, fn ($q, $v) => $q->where('status', $v))->latest()->paginate(50));
    }

    public function storeRequisition(Request $request): JsonResponse
    {
        Gate::authorize('procurement.requisition');
        $data = $request->validate([
            'warehouse_id' => ['required', 'ulid', $this->own('warehouses')],
            'needed_by' => ['nullable', 'date', 'after_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.product_id' => ['required', 'ulid'],
            'lines.*.quantity' => ['required', 'decimal:0,4', 'gt:0'],
            'lines.*.package_level_code' => ['nullable', 'string', 'max:32'],
            'lines.*.suggested_supplier_id' => ['nullable', 'ulid', $this->own('suppliers')],
            'lines.*.notes' => ['nullable', 'string', 'max:255'],
        ]);
        $this->locations->assertWarehouse($data['warehouse_id']);

        return response()->json(['data' => $this->orders->createRequisition(Warehouse::query()->findOrFail($data['warehouse_id']), $data['lines'], $data['needed_by'] ?? null, $data['notes'] ?? null)], 201);
    }

    public function submitRequisition(string $id): JsonResponse
    {
        Gate::authorize('procurement.requisition');

        return response()->json(['data' => $this->orders->submitRequisition(PurchaseRequisition::query()->findOrFail($id))]);
    }

    public function decideRequisition(Request $request, string $id, string $decision): JsonResponse
    {
        Gate::authorize('procurement.approve');
        $data = $request->validate(['notes' => ['nullable', 'string', 'max:2000']]);

        return response()->json(['data' => $this->orders->decideRequisition(PurchaseRequisition::query()->findOrFail($id), $decision === 'approve', $request->user(), $data['notes'] ?? null)]);
    }

    public function convertRequisition(Request $request, string $id): JsonResponse
    {
        Gate::authorize('procurement.order');
        $data = $request->validate(['supplier_id' => ['nullable', 'ulid', $this->own('suppliers')]]);

        return response()->json(['data' => $this->orders->convertRequisition(PurchaseRequisition::query()->findOrFail($id), $data['supplier_id'] ?? null)], 201);
    }

    // ---- Purchase orders ----------------------------------------------------

    public function orders(Request $request): JsonResponse
    {
        Gate::authorize('procurement.view');
        $f = $request->validate([
            'status' => ['nullable', 'string', 'max:20'],
            'supplier_id' => ['nullable', 'ulid'],
            'product_id' => ['nullable', 'ulid'],
        ]);

        return response()->json($this->locations->scope(PurchaseOrder::query()->with('lines'))
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['supplier_id'] ?? null, fn ($q, $v) => $q->where('supplier_id', $v))
            ->when($f['product_id'] ?? null, fn ($q, $v) => $q->whereHas('lines', fn ($l) => $l->where('product_id', $v)))
            ->latest()->paginate(50));
    }

    public function showOrder(string $id): JsonResponse
    {
        Gate::authorize('procurement.view');
        $order = PurchaseOrder::query()->with('lines')->findOrFail($id);
        abort_unless($this->locations->canAccessWarehouse($order->warehouse_id), 404);

        return response()->json(['data' => $order->toArray() + ['goods_receipts' => GoodsReceipt::query()->where('purchase_order_id', $order->id)->get(['id', 'number', 'received_at'])]]);
    }

    public function storeOrder(Request $request): JsonResponse
    {
        Gate::authorize('procurement.order');
        $data = $request->validate([
            'supplier_id' => ['required', 'ulid', $this->own('suppliers')],
            'warehouse_id' => ['required', 'ulid', $this->own('warehouses')],
            'expected_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ] + $this->lineRules());
        $this->locations->assertWarehouse($data['warehouse_id']);

        $order = $this->orders->createOrder(Supplier::query()->findOrFail($data['supplier_id']), Warehouse::query()->findOrFail($data['warehouse_id']),
            $data['lines'], $data['expected_date'] ?? null, $data['notes'] ?? null);

        return response()->json(['data' => $order], 201);
    }

    public function updateOrder(Request $request, string $id): JsonResponse
    {
        Gate::authorize('procurement.order');
        $rules = $this->lineRules();
        $rules['lines'][0] = 'sometimes'; // lines optional on update; when present they replace all lines
        $data = $request->validate(['expected_date' => ['sometimes', 'nullable', 'date'], 'notes' => ['sometimes', 'nullable', 'string', 'max:2000']] + $rules);

        return response()->json(['data' => $this->orders->updateOrder(PurchaseOrder::query()->findOrFail($id), $data)]);
    }

    public function orderAction(Request $request, string $id, string $action): JsonResponse
    {
        $order = PurchaseOrder::query()->findOrFail($id);
        $this->locations->assertWarehouse($order->warehouse_id);

        $result = match ($action) {
            'submit' => $this->authorized('procurement.order', fn () => $this->orders->submitOrder($order, $request->user())),
            'approve' => $this->authorized('procurement.approve', fn () => $this->orders->decideOrder($order, true, $request->user())),
            'reject' => $this->authorized('procurement.approve', fn () => $this->orders->decideOrder($order, false, $request->user(), $request->validate(['reason' => ['required', 'string', 'max:2000']])['reason'])),
            'send' => $this->authorized('procurement.order', fn () => $this->orders->markSent($order)),
            'cancel' => $this->authorized('procurement.order', fn () => $this->orders->cancel($order, $request->validate(['reason' => ['required', 'string', 'max:2000']])['reason'])),
            'close' => $this->authorized('procurement.order', fn () => $this->orders->close($order, $request->validate(['reason' => ['required', 'string', 'max:2000']])['reason'])),
        };

        return response()->json(['data' => $result]);
    }

    // ---- Goods receipts -----------------------------------------------------

    public function goodsReceipts(Request $request): JsonResponse
    {
        Gate::authorize('procurement.view');
        $po = $request->validate(['purchase_order_id' => ['nullable', 'ulid']])['purchase_order_id'] ?? null;

        return response()->json($this->locations->scope(GoodsReceipt::query()->with('lines'))
            ->when($po, fn ($q, $v) => $q->where('purchase_order_id', $v))->latest('received_at')->paginate(50));
    }

    public function storeGoodsReceipt(Request $request, GoodsReceiptService $receipts): JsonResponse
    {
        Gate::authorize('inventory.receive');
        Gate::authorize('procurement.view');
        $data = $request->validate([
            'purchase_order_id' => ['required', 'ulid', $this->own('purchase_orders')],
            'supplier_delivery_note' => ['nullable', 'string', 'max:64'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.purchase_order_line_id' => ['required', 'integer'],
            'lines.*.batch_number' => ['required', 'string', 'max:64'],
            'lines.*.expiry_date' => ['nullable', 'date'],
            'lines.*.manufacturing_date' => ['nullable', 'date', 'before_or_equal:today'],
            'lines.*.quantity' => ['required', 'decimal:0,4', 'gt:0'],
            'lines.*.quality_status' => ['nullable', Rule::in(['released', 'quarantined'])],
            'lines.*.bin_location_id' => ['nullable', 'ulid'],
        ]);
        $order = PurchaseOrder::query()->findOrFail($data['purchase_order_id']);
        $this->locations->assertWarehouse($order->warehouse_id);

        return response()->json(['data' => $receipts->receive($order, $data['lines'], $data['supplier_delivery_note'] ?? null, $data['notes'] ?? null)], 201);
    }

    // ---- Supplier invoices --------------------------------------------------

    public function invoices(Request $request): JsonResponse
    {
        Gate::authorize('procurement.view');
        $f = $request->validate(['status' => ['nullable', 'string'], 'match_status' => ['nullable', 'string'], 'supplier_id' => ['nullable', 'ulid']]);

        return response()->json(SupplierInvoice::query()->with('lines')
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['match_status'] ?? null, fn ($q, $v) => $q->where('match_status', $v))
            ->when($f['supplier_id'] ?? null, fn ($q, $v) => $q->where('supplier_id', $v))
            ->latest()->paginate(50));
    }

    public function storeInvoice(Request $request, SupplierInvoiceService $invoices): JsonResponse
    {
        Gate::authorize('procurement.invoice');
        $data = $request->validate([
            'supplier_id' => ['required', 'ulid', $this->own('suppliers')],
            'purchase_order_id' => ['nullable', 'ulid', $this->own('purchase_orders')],
            'supplier_invoice_number' => ['required', 'string', 'max:64'],
            'invoice_date' => ['required', 'date', 'before_or_equal:today'],
            'due_date' => ['nullable', 'date', 'after_or_equal:invoice_date'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.purchase_order_line_id' => ['nullable', 'integer'],
            'lines.*.description' => ['nullable', 'string', 'max:255', 'required_without:lines.*.purchase_order_line_id'],
            'lines.*.quantity' => ['required', 'decimal:0,4', 'gt:0'],
            'lines.*.unit_price' => ['required', 'decimal:0,4', 'min:0'],
            'lines.*.tax_rate' => ['nullable', 'decimal:0,4', 'between:0,100'],
        ]);

        $invoice = $invoices->record(
            Supplier::query()->findOrFail($data['supplier_id']),
            isset($data['purchase_order_id']) ? PurchaseOrder::query()->findOrFail($data['purchase_order_id']) : null,
            trim($data['supplier_invoice_number']), $data['invoice_date'], $data['due_date'] ?? null, $data['lines'],
        );

        return response()->json(['data' => $invoice], 201);
    }

    public function invoiceAction(Request $request, string $id, string $action, SupplierInvoiceService $invoices): JsonResponse
    {
        $invoice = SupplierInvoice::query()->findOrFail($id);

        $result = match ($action) {
            'approve' => $this->authorized('procurement.approve', fn () => $invoices->approve($invoice, $request->user(), $request->validate(['override_reason' => ['nullable', 'string', 'max:2000']])['override_reason'] ?? null)),
            'cancel' => $this->authorized('procurement.invoice', fn () => $invoices->cancel($invoice, $request->validate(['reason' => ['required', 'string', 'max:2000']])['reason'])),
        };

        return response()->json(['data' => $result]);
    }

    // ---- Supplier returns ---------------------------------------------------

    public function returns(): JsonResponse
    {
        Gate::authorize('procurement.view');

        return response()->json($this->locations->scope(SupplierReturn::query()->with('lines'))->latest()->paginate(50));
    }

    public function storeReturn(Request $request, SupplierReturnService $returns): JsonResponse
    {
        Gate::authorize('procurement.return');
        $data = $request->validate([
            'supplier_id' => ['required', 'ulid', $this->own('suppliers')],
            'warehouse_id' => ['required', 'ulid', $this->own('warehouses')],
            'goods_receipt_id' => ['nullable', 'ulid', $this->own('goods_receipts')],
            'reason_code' => ['required', Rule::in(SupplierReturnService::REASONS)],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.batch_id' => ['required', 'ulid'],
            'lines.*.base_quantity' => ['required', 'decimal:0,4', 'gt:0'],
            'lines.*.stock_state' => ['nullable', Rule::in(['available', 'quarantined', 'damaged'])],
        ]);
        $this->locations->assertWarehouse($data['warehouse_id']);

        $return = $returns->post(Supplier::query()->findOrFail($data['supplier_id']), Warehouse::query()->findOrFail($data['warehouse_id']),
            $data['reason_code'], $data['lines'], $data['goods_receipt_id'] ?? null, $data['notes'] ?? null);

        return response()->json(['data' => $return], 201);
    }

    // ---- Price lists, performance, suggestions, settings ---------------------

    public function priceList(string $supplierId): JsonResponse
    {
        Gate::authorize('procurement.view');
        $supplier = Supplier::query()->findOrFail($supplierId);

        return response()->json(['data' => SupplierPriceListItem::query()->where('supplier_id', $supplier->id)->orderBy('product_id')->get()]);
    }

    public function replacePriceList(Request $request, string $supplierId, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('procurement.order');
        $supplier = Supplier::query()->findOrFail($supplierId);
        $data = $request->validate([
            'items' => ['present', 'array', 'max:2000'],
            'items.*.product_id' => ['required', 'ulid', 'exists:products,id'],
            'items.*.package_level_code' => ['required', 'string', 'max:32'],
            'items.*.unit_price' => ['required', 'decimal:0,4', 'min:0'],
            'items.*.minimum_quantity' => ['nullable', 'decimal:0,4', 'gt:0'],
            'items.*.valid_from' => ['nullable', 'date'],
            'items.*.valid_to' => ['nullable', 'date'],
        ]);

        DB::transaction(function () use ($supplier, $data) {
            SupplierPriceListItem::query()->where('supplier_id', $supplier->id)->delete();
            foreach ($data['items'] as $item) {
                SupplierPriceListItem::query()->create($item + ['supplier_id' => $supplier->id, 'currency_code' => $supplier->currency_code, 'minimum_quantity' => $item['minimum_quantity'] ?? 1]);
            }
        });
        $audit->record('supplier_price_list.replaced', $supplier, new: ['items' => count($data['items'])]);

        return $this->priceList($supplier->id);
    }

    /** Delivery performance from recorded purchase orders and receipts. */
    public function supplierPerformance(string $supplierId): JsonResponse
    {
        Gate::authorize('procurement.view');
        $supplier = Supplier::query()->findOrFail($supplierId);

        $firstReceipts = DB::table('goods_receipts')->where('supplier_id', $supplier->id)->where('organization_id', $this->tenant->id())
            ->groupBy('purchase_order_id')->selectRaw('purchase_order_id, MIN(received_at) as first_received_at');

        $orders = DB::table('purchase_orders as po')
            ->leftJoinSub($firstReceipts, 'fr', 'fr.purchase_order_id', '=', 'po.id')
            ->where('po.organization_id', $this->tenant->id())->where('po.supplier_id', $supplier->id)
            ->whereNotIn('po.status', ['draft', 'pending_approval', 'rejected', 'cancelled'])
            ->get(['po.id', 'po.expected_date', 'po.approved_at', 'fr.first_received_at']);

        $received = $orders->whereNotNull('first_received_at');
        $withExpected = $received->whereNotNull('expected_date');
        $onTime = $withExpected->filter(fn ($o) => substr($o->first_received_at, 0, 10) <= $o->expected_date)->count();
        $leadDays = $received->filter(fn ($o) => $o->approved_at)->map(fn ($o) => (strtotime($o->first_received_at) - strtotime($o->approved_at)) / 86400);

        $quantities = DB::table('purchase_order_lines as l')->join('purchase_orders as po', 'po.id', '=', 'l.purchase_order_id')
            ->where('po.organization_id', $this->tenant->id())->where('po.supplier_id', $supplier->id)->whereIn('po.status', ['received', 'closed', 'partially_received'])
            ->selectRaw('SUM(l.base_quantity) as ordered, SUM(l.received_base_quantity) as received')->first();

        return response()->json(['data' => [
            'supplier_id' => $supplier->id,
            'orders_placed' => $orders->count(),
            'orders_with_receipts' => $received->count(),
            'on_time_rate' => $withExpected->count() ? round($onTime / $withExpected->count(), 4) : null,
            'average_lead_time_days' => $leadDays->count() ? round($leadDays->avg(), 1) : null,
            'fill_rate' => $quantities && (float) $quantities->ordered > 0 ? round((float) $quantities->received / (float) $quantities->ordered, 4) : null,
            'invoices_with_variance' => SupplierInvoice::query()->where('supplier_id', $supplier->id)->whereIn('match_status', ['variance', 'unmatched'])->count(),
            'returns' => SupplierReturn::query()->where('supplier_id', $supplier->id)->count(),
        ], 'meta' => ['note' => 'Computed from this organization\'s recorded orders and receipts only.']]);
    }

    /** Low-stock items with a suggested order quantity, preferred supplier and current list price. */
    public function suggestions(StockQueries $queries): JsonResponse
    {
        Gate::authorize('procurement.view');
        $today = business_today()->toDateString();

        $suggestions = $queries->lowStock()->map(fn ($row) => (array) $row)->map(function ($row) use ($today) {
            $target = bcmul((string) $row['reorder_level'], '2', 4);
            $quantity = $row['reorder_quantity'] !== null ? (string) $row['reorder_quantity'] : bcsub($target, (string) $row['sellable'], 4);
            $price = $row['preferred_supplier_id'] ? SupplierPriceListItem::query()->where('supplier_id', $row['preferred_supplier_id'])->where('product_id', $row['product_id'])
                ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $today))->orderBy('minimum_quantity')->first(['package_level_code', 'unit_price']) : null;

            return $row + ['suggested_base_quantity' => $quantity, 'list_price' => $price];
        });

        return response()->json(['data' => $suggestions->values(), 'meta' => ['note' => 'Suggestions only. A person decides whether to raise a requisition or order.']]);
    }

    public function settings(ProcurementPolicy $policy): JsonResponse
    {
        Gate::authorize('procurement.view');

        return response()->json(['data' => $policy->settings()]);
    }

    public function updateSettings(Request $request, ProcurementPolicy $policy, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('procurement.settings.manage');
        $data = $request->validate([
            'approval_threshold' => ['sometimes', 'nullable', 'decimal:0,4', 'min:0'],
            'allow_self_approval' => ['sometimes', 'boolean'],
            'over_receipt_tolerance_percent' => ['sometimes', 'decimal:0,4', 'between:0,50'],
        ]);

        $organization = $this->tenant->organization();
        $before = $policy->settings();
        $settings = $organization->settings ?? [];
        foreach ($data as $key => $value) {
            data_set($settings, "procurement.{$key}", $value);
        }
        $organization->forceFill(['settings' => $settings])->save();
        $audit->record('procurement.settings_updated', $organization, $before, $data);

        return response()->json(['data' => $policy->settings()]);
    }

    private function lineRules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.product_id' => ['required', 'ulid'],
            'lines.*.quantity' => ['required', 'decimal:0,4', 'gt:0'],
            'lines.*.package_level_code' => ['nullable', 'string', 'max:32'],
            'lines.*.unit_price' => ['nullable', 'decimal:0,4', 'min:0'],
            'lines.*.tax_rate' => ['nullable', 'decimal:0,4', 'between:0,100'],
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

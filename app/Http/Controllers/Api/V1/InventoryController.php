<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Inventory\Models\BinLocation;
use App\Domain\Inventory\Models\InventoryMovement;
use App\Domain\Inventory\Services\FefoAllocator;
use App\Domain\Inventory\Services\LocationAccess;
use App\Domain\Inventory\Services\StockReceiptService;
use App\Domain\Organizations\Models\Warehouse;
use App\Domain\Platform\Services\AuditLogger;
use App\Http\Controllers\Controller;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Stock visibility, receipts and inventory policy. All reads are limited
 * to the caller's accessible warehouses (branch/warehouse scopes).
 */
class InventoryController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly LocationAccess $locations,
    ) {}

    /** Per product and warehouse: on hand, reserved, sellable, quarantined, nearest sellable expiry. */
    public function stockLevels(Request $request): JsonResponse
    {
        Gate::authorize('inventory.view');
        $filters = $request->validate([
            'product_id' => ['nullable', 'ulid'],
            'warehouse_id' => ['nullable', 'ulid'],
            'per_page' => ['nullable', 'integer', 'between:1,200'],
        ]);

        $today = today()->toDateString();
        $sellable = "b.stock_state = 'available' AND bt.quality_status = 'released' AND bt.recall_status = 'none' AND (bt.expiry_date IS NULL OR bt.expiry_date >= '{$today}')";

        $query = DB::table('inventory_balances as b')
            ->join('batches as bt', 'bt.id', '=', 'b.batch_id')
            ->join('products as p', 'p.id', '=', 'b.product_id')
            ->join('warehouses as w', 'w.id', '=', 'b.warehouse_id')
            ->where('b.organization_id', $this->tenant->id())
            ->when($filters['product_id'] ?? null, fn ($q, $v) => $q->where('b.product_id', $v))
            ->when($filters['warehouse_id'] ?? null, fn ($q, $v) => $q->where('b.warehouse_id', $v))
            ->groupBy('b.product_id', 'b.warehouse_id', 'p.display_name', 'w.code')
            ->selectRaw("b.product_id, b.warehouse_id, p.display_name as product_name, w.code as warehouse_code,
                SUM(CASE WHEN b.stock_state = 'available' THEN b.quantity ELSE 0 END) as available_on_hand,
                SUM(b.reserved_quantity) as reserved,
                SUM(CASE WHEN {$sellable} THEN b.quantity - b.reserved_quantity ELSE 0 END) as sellable,
                SUM(CASE WHEN b.stock_state = 'available' AND NOT ({$sellable}) THEN b.quantity ELSE 0 END) as blocked_in_available,
                SUM(CASE WHEN b.stock_state = 'quarantined' THEN b.quantity ELSE 0 END) as quarantined,
                SUM(CASE WHEN b.stock_state = 'damaged' THEN b.quantity ELSE 0 END) as damaged,
                SUM(CASE WHEN b.stock_state = 'in_transit' THEN b.quantity ELSE 0 END) as in_transit,
                MIN(CASE WHEN {$sellable} AND b.quantity > b.reserved_quantity THEN bt.expiry_date END) as nearest_sellable_expiry")
            ->orderBy('p.display_name');

        $this->locations->scope($query, 'b.warehouse_id');

        return response()->json($query->paginate($filters['per_page'] ?? 50)->through(fn ($row) => (array) $row + ['unit' => 'base']));
    }

    /** Batch-level balances. */
    public function balances(Request $request): JsonResponse
    {
        Gate::authorize('inventory.view');
        $filters = $request->validate([
            'product_id' => ['nullable', 'ulid'], 'warehouse_id' => ['nullable', 'ulid'], 'batch_id' => ['nullable', 'ulid'],
            'stock_state' => ['nullable', Rule::in(['available', 'quarantined', 'damaged', 'in_transit'])],
            'include_zero' => ['nullable', 'boolean'],
        ]);

        $query = DB::table('inventory_balances as b')
            ->join('batches as bt', 'bt.id', '=', 'b.batch_id')
            ->where('b.organization_id', $this->tenant->id())
            ->when(! ($filters['include_zero'] ?? false), fn ($q) => $q->where('b.quantity', '!=', 0))
            ->when($filters['product_id'] ?? null, fn ($q, $v) => $q->where('b.product_id', $v))
            ->when($filters['warehouse_id'] ?? null, fn ($q, $v) => $q->where('b.warehouse_id', $v))
            ->when($filters['batch_id'] ?? null, fn ($q, $v) => $q->where('b.batch_id', $v))
            ->when($filters['stock_state'] ?? null, fn ($q, $v) => $q->where('b.stock_state', $v))
            ->select(['b.warehouse_id', 'b.product_id', 'b.batch_id', 'bt.batch_number', 'bt.expiry_date', 'bt.quality_status', 'bt.recall_status',
                'b.stock_state', 'b.quantity', 'b.reserved_quantity', 'b.updated_at'])
            ->orderBy('bt.expiry_date');

        $this->locations->scope($query, 'b.warehouse_id');

        return response()->json($query->paginate(100));
    }

    public function movements(Request $request): JsonResponse
    {
        Gate::authorize('inventory.view');
        $filters = $request->validate([
            'product_id' => ['nullable', 'ulid'], 'warehouse_id' => ['nullable', 'ulid'], 'batch_id' => ['nullable', 'ulid'],
            'movement_type' => ['nullable', 'string', 'max:24'], 'posting_id' => ['nullable', 'ulid'],
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'],
        ]);
        $showCost = Gate::allows('inventory.valuation.view');

        $query = InventoryMovement::query()
            ->when($filters['product_id'] ?? null, fn ($q, $v) => $q->where('product_id', $v))
            ->when($filters['warehouse_id'] ?? null, fn ($q, $v) => $q->where('warehouse_id', $v))
            ->when($filters['batch_id'] ?? null, fn ($q, $v) => $q->where('batch_id', $v))
            ->when($filters['movement_type'] ?? null, fn ($q, $v) => $q->where('movement_type', $v))
            ->when($filters['posting_id'] ?? null, fn ($q, $v) => $q->where('posting_id', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('posted_at', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('posted_at', '<', Carbon::parse($v)->addDay()))
            ->orderByDesc('posted_at')->orderByDesc('id');
        $this->locations->scope($query);

        return response()->json($query->paginate(100)->through(fn (InventoryMovement $m) => collect($m->attributesToArray())
            ->except(array_filter(['organization_id', $showCost ? null : 'unit_cost']))->all()));
    }

    public function expiring(Request $request): JsonResponse
    {
        Gate::authorize('inventory.view');
        $days = (int) ($request->validate(['days' => ['nullable', 'integer', 'between:0,730']])['days'] ?? 90);

        $query = DB::table('inventory_balances as b')
            ->join('batches as bt', 'bt.id', '=', 'b.batch_id')
            ->join('products as p', 'p.id', '=', 'b.product_id')
            ->where('b.organization_id', $this->tenant->id())
            ->where('b.quantity', '>', 0)
            ->whereNotNull('bt.expiry_date')
            ->where('bt.expiry_date', '<=', today()->addDays($days)->toDateString())
            ->select(['b.warehouse_id', 'b.product_id', 'p.display_name as product_name', 'b.batch_id', 'bt.batch_number', 'bt.expiry_date', 'b.stock_state', 'b.quantity'])
            ->selectRaw('bt.expiry_date < ? as expired', [today()->toDateString()])
            ->orderBy('bt.expiry_date');
        $this->locations->scope($query, 'b.warehouse_id');

        return response()->json(['data' => $query->get(), 'meta' => ['within_days' => $days]]);
    }

    /** Products whose sellable stock is at or below their reorder level. */
    public function lowStock(): JsonResponse
    {
        Gate::authorize('inventory.view');
        $today = today()->toDateString();

        $rows = DB::table('organization_products as op')
            ->join('products as p', 'p.id', '=', 'op.product_id')
            ->leftJoin('inventory_balances as b', fn ($j) => $j->on('b.product_id', '=', 'op.product_id')->on('b.organization_id', '=', 'op.organization_id')->where('b.stock_state', 'available'))
            ->leftJoin('batches as bt', 'bt.id', '=', 'b.batch_id')
            ->where('op.organization_id', $this->tenant->id())
            ->whereNotNull('op.reorder_level')
            ->groupBy('op.id', 'op.product_id', 'op.sku', 'p.display_name', 'op.reorder_level', 'op.reorder_quantity', 'op.preferred_supplier_id')
            ->selectRaw("op.product_id, op.sku, p.display_name as product_name, op.reorder_level, op.reorder_quantity, op.preferred_supplier_id,
                COALESCE(SUM(CASE WHEN bt.quality_status = 'released' AND bt.recall_status = 'none' AND (bt.expiry_date IS NULL OR bt.expiry_date >= ?) THEN b.quantity - b.reserved_quantity END), 0) as sellable", [$today])
            ->havingRaw('sellable <= op.reorder_level')
            ->orderBy('p.display_name')
            ->get();

        return response()->json(['data' => $rows, 'meta' => ['note' => 'Organization-wide sellable stock compared with the reorder level, in base units.']]);
    }

    /** Valuation at batch cost (specific identification). Batches without a recorded cost are reported separately. */
    public function valuation(): JsonResponse
    {
        Gate::authorize('inventory.valuation.view');

        $query = DB::table('inventory_balances as b')
            ->join('batches as bt', 'bt.id', '=', 'b.batch_id')
            ->where('b.organization_id', $this->tenant->id())
            ->where('b.quantity', '>', 0)
            ->groupBy('b.warehouse_id', 'b.stock_state', 'bt.currency_code')
            ->selectRaw('b.warehouse_id, b.stock_state, bt.currency_code,
                SUM(CASE WHEN bt.unit_cost IS NOT NULL THEN b.quantity * bt.unit_cost ELSE 0 END) as value,
                SUM(CASE WHEN bt.unit_cost IS NULL THEN b.quantity ELSE 0 END) as uncosted_quantity');
        $this->locations->scope($query, 'b.warehouse_id');

        return response()->json(['data' => $query->get()->map(fn ($r) => array_merge((array) $r, [
            // SUM of decimal products comes back with extra scale; normalise to 4 places without float conversion.
            'value' => bcadd((string) $r->value, '0', 4),
            'uncosted_quantity' => bcadd((string) $r->uncosted_quantity, '0', 4),
        ]))]);
    }

    /** Dry-run FEFO plan (no locks, no reservation). Useful for POS and order entry screens. */
    public function previewAllocation(Request $request, FefoAllocator $fefo): JsonResponse
    {
        Gate::authorize('inventory.view');
        $data = $request->validate([
            'product_id' => ['required', 'ulid'],
            'warehouse_id' => ['required', 'ulid', Rule::exists('warehouses', 'id')->where('organization_id', $this->tenant->id())],
            'quantity' => ['required', 'numeric', 'gt:0'],
        ]);
        $this->locations->assertWarehouse($data['warehouse_id']);

        $plan = $fefo->allocate($data['product_id'], $data['warehouse_id'], (string) $data['quantity']);

        return response()->json(['data' => collect($plan)->map(fn ($qty, $batchId) => ['batch_id' => $batchId, 'quantity' => $qty])->values(),
            'meta' => ['note' => 'Preview only; stock is not reserved.']]);
    }

    public function receive(Request $request, StockReceiptService $receipts): JsonResponse
    {
        Gate::authorize('inventory.receive');
        $data = $request->validate([
            'warehouse_id' => ['required', 'ulid', Rule::exists('warehouses', 'id')->where('organization_id', $this->tenant->id())],
            'supplier_id' => ['nullable', 'ulid', Rule::exists('suppliers', 'id')->where('organization_id', $this->tenant->id())],
            'reason_code' => ['nullable', Rule::in(['receipt', 'opening_balance', 'supplier_delivery', 'customer_return', 'other'])],
            'reference' => ['nullable', 'string', 'max:64'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.product_id' => ['required', 'ulid'],
            'lines.*.batch_number' => ['required', 'string', 'max:64'],
            'lines.*.expiry_date' => ['nullable', 'date'],
            'lines.*.manufacturing_date' => ['nullable', 'date', 'before_or_equal:today'],
            'lines.*.quantity' => ['required', 'decimal:0,4', 'gt:0'],
            'lines.*.package_level_code' => ['nullable', 'string', 'max:32'],
            'lines.*.unit_cost' => ['nullable', 'decimal:0,4', 'min:0'],
            'lines.*.quality_status' => ['nullable', Rule::in(['released', 'quarantined'])],
            'lines.*.bin_location_id' => ['nullable', 'ulid', Rule::exists('bin_locations', 'id')->where('warehouse_id', $request->input('warehouse_id'))],
        ]);
        $this->locations->assertWarehouse($data['warehouse_id']);

        $result = $receipts->receive(Warehouse::query()->findOrFail($data['warehouse_id']), $data['lines'], $data['supplier_id'] ?? null,
            'external_reference', null, $data['reason_code'] ?? 'receipt');

        return response()->json(['data' => $result + ['reference' => $data['reference'] ?? null]], 201);
    }

    public function settings(): JsonResponse
    {
        Gate::authorize('inventory.view');

        return response()->json(['data' => $this->currentSettings()]);
    }

    public function updateSettings(Request $request, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('inventory.settings.manage');
        $data = $request->validate([
            'allow_negative_stock' => ['sometimes', 'boolean'],
            'min_shelf_life_days' => ['sometimes', 'integer', 'between:0,730'],
        ]);

        $organization = $this->tenant->organization();
        $before = $this->currentSettings();
        $settings = $organization->settings ?? [];
        foreach ($data as $key => $value) {
            data_set($settings, "inventory.{$key}", $value);
        }
        $organization->forceFill(['settings' => $settings])->save();
        $audit->record('inventory.settings_updated', $organization, $before, $data);

        return response()->json(['data' => $this->currentSettings()]);
    }

    public function bins(string $warehouseId): JsonResponse
    {
        Gate::authorize('inventory.view');
        $warehouse = Warehouse::query()->findOrFail($warehouseId);
        $this->locations->assertWarehouse($warehouse->id);

        return response()->json(['data' => BinLocation::query()->where('warehouse_id', $warehouse->id)->orderBy('code')->get(['id', 'code', 'name', 'status'])]);
    }

    public function storeBin(Request $request, string $warehouseId, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('inventory.settings.manage');
        $warehouse = Warehouse::query()->findOrFail($warehouseId);
        $this->locations->assertWarehouse($warehouse->id);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:32', 'alpha_dash', Rule::unique('bin_locations')->where('warehouse_id', $warehouse->id)],
            'name' => ['nullable', 'string', 'max:255'],
        ]);
        $bin = BinLocation::query()->create($data + ['warehouse_id' => $warehouse->id]);
        $audit->record('bin_location.created', $bin, new: $data);

        return response()->json(['data' => $bin->only(['id', 'code', 'name', 'status'])], 201);
    }

    private function currentSettings(): array
    {
        $settings = $this->tenant->organization()->settings ?? [];

        return [
            'allow_negative_stock' => (bool) data_get($settings, 'inventory.allow_negative_stock', false),
            'min_shelf_life_days' => (int) data_get($settings, 'inventory.min_shelf_life_days', 0),
        ];
    }
}

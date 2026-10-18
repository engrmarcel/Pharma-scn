<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Inventory\Models\StockAdjustment;
use App\Domain\Inventory\Models\StockCount;
use App\Domain\Inventory\Models\StockTransfer;
use App\Domain\Inventory\Services\LocationAccess;
use App\Domain\Inventory\Services\StockDocumentService;
use App\Domain\Organizations\Models\Warehouse;
use App\Http\Controllers\Controller;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Adjustments, transfers and counts. Warehouse access scopes apply to every
 * document: transfers need access to both ends for dispatch (source) and
 * receipt (destination).
 */
class StockDocumentController extends Controller
{
    public function __construct(
        private readonly StockDocumentService $documents,
        private readonly LocationAccess $locations,
        private readonly TenantContext $tenant,
    ) {}

    public function adjustments(): JsonResponse
    {
        Gate::authorize('inventory.view');

        return response()->json($this->locations->scope(StockAdjustment::query()->with('lines'))->latest()->paginate(50));
    }

    public function storeAdjustment(Request $request): JsonResponse
    {
        Gate::authorize('inventory.adjust');
        $data = $request->validate([
            'warehouse_id' => ['required', 'ulid', $this->ownWarehouse()],
            'reason_code' => ['required', Rule::in(StockDocumentService::ADJUSTMENT_REASONS)],
            'notes' => ['nullable', 'required_if:reason_code,other', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.batch_id' => ['required', 'ulid'],
            'lines.*.stock_state' => ['nullable', Rule::in(['available', 'quarantined', 'damaged'])],
            'lines.*.quantity_delta' => ['required', 'decimal:0,4', 'not_in:0'],
        ]);
        $this->locations->assertWarehouse($data['warehouse_id']);

        $adjustment = $this->documents->adjust(Warehouse::query()->findOrFail($data['warehouse_id']), $data['reason_code'], $data['lines'], $data['notes'] ?? null);

        return response()->json(['data' => $adjustment], 201);
    }

    public function transfers(Request $request): JsonResponse
    {
        Gate::authorize('inventory.view');
        $status = $request->validate(['status' => ['nullable', Rule::in(['draft', 'dispatched', 'partially_received', 'received', 'cancelled'])]])['status'] ?? null;
        $ids = $this->locations->warehouseIds();

        return response()->json(StockTransfer::query()->with('lines')
            ->when($status, fn ($q, $v) => $q->where('status', $v))
            ->when($ids !== null, fn ($q) => $q->where(fn ($w) => $w->whereIn('from_warehouse_id', $ids)->orWhereIn('to_warehouse_id', $ids)))
            ->latest()->paginate(50));
    }

    public function showTransfer(string $id): JsonResponse
    {
        Gate::authorize('inventory.view');
        $transfer = StockTransfer::query()->with('lines')->findOrFail($id);
        abort_unless($this->locations->canAccessWarehouse($transfer->from_warehouse_id) || $this->locations->canAccessWarehouse($transfer->to_warehouse_id), 404);

        return response()->json(['data' => $transfer]);
    }

    public function storeTransfer(Request $request): JsonResponse
    {
        Gate::authorize('inventory.transfer');
        $data = $request->validate([
            'from_warehouse_id' => ['required', 'ulid', $this->ownWarehouse()],
            'to_warehouse_id' => ['required', 'ulid', 'different:from_warehouse_id', $this->ownWarehouse()],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.product_id' => ['required', 'ulid', 'exists:products,id'],
            'lines.*.quantity' => ['required', 'decimal:0,4', 'gt:0'],
            'lines.*.batch_id' => ['nullable', 'ulid', Rule::exists('batches', 'id')->where('organization_id', $this->tenant->id())],
        ]);
        $this->locations->assertWarehouse($data['from_warehouse_id']);

        $transfer = $this->documents->createTransfer(
            Warehouse::query()->findOrFail($data['from_warehouse_id']),
            Warehouse::query()->findOrFail($data['to_warehouse_id']),
            $data['lines'], $data['notes'] ?? null,
        );

        return response()->json(['data' => $transfer], 201);
    }

    public function dispatchTransfer(string $id): JsonResponse
    {
        Gate::authorize('inventory.transfer');
        $transfer = StockTransfer::query()->findOrFail($id);
        $this->locations->assertWarehouse($transfer->from_warehouse_id);

        return response()->json(['data' => $this->documents->dispatchTransfer($transfer)]);
    }

    public function receiveTransfer(Request $request, string $id): JsonResponse
    {
        Gate::authorize('inventory.transfer');
        $transfer = StockTransfer::query()->findOrFail($id);
        $this->locations->assertWarehouse($transfer->to_warehouse_id);

        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.line_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'decimal:0,4', 'gt:0'],
        ]);

        return response()->json(['data' => $this->documents->receiveTransfer($transfer, collect($data['lines'])->pluck('quantity', 'line_id')->all())]);
    }

    public function cancelTransfer(string $id): JsonResponse
    {
        Gate::authorize('inventory.transfer');
        $transfer = StockTransfer::query()->findOrFail($id);
        $this->locations->assertWarehouse($transfer->from_warehouse_id);

        return response()->json(['data' => $this->documents->cancelTransfer($transfer)]);
    }

    public function counts(): JsonResponse
    {
        Gate::authorize('inventory.view');

        return response()->json($this->locations->scope(StockCount::query()->with('lines'))->latest()->paginate(50));
    }

    public function storeCount(Request $request): JsonResponse
    {
        Gate::authorize('inventory.count');
        $data = $request->validate(['warehouse_id' => ['required', 'ulid', $this->ownWarehouse()], 'notes' => ['nullable', 'string', 'max:2000']]);
        $this->locations->assertWarehouse($data['warehouse_id']);

        return response()->json(['data' => $this->documents->openCount(Warehouse::query()->findOrFail($data['warehouse_id']), $data['notes'] ?? null)], 201);
    }

    public function recordCountLines(Request $request, string $id): JsonResponse
    {
        Gate::authorize('inventory.count');
        $count = StockCount::query()->findOrFail($id);
        $this->locations->assertWarehouse($count->warehouse_id);

        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.batch_id' => ['required', 'ulid'],
            'lines.*.counted_quantity' => ['required', 'decimal:0,4', 'min:0'],
        ]);

        return response()->json(['data' => $this->documents->recordCountLines($count, $data['lines'])]);
    }

    public function postCount(string $id): JsonResponse
    {
        // Posting changes stock, so it needs adjustment rights as well as counting rights.
        Gate::authorize('inventory.count');
        Gate::authorize('inventory.adjust');
        $count = StockCount::query()->findOrFail($id);
        $this->locations->assertWarehouse($count->warehouse_id);

        return response()->json(['data' => $this->documents->postCount($count)]);
    }

    private function ownWarehouse()
    {
        return Rule::exists('warehouses', 'id')->where('organization_id', $this->tenant->id());
    }
}

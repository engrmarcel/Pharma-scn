<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Inventory\Models\Batch;
use App\Domain\Inventory\Services\BatchQualityService;
use App\Domain\Inventory\Services\LocationAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class BatchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('inventory.view');
        $filters = $request->validate([
            'product_id' => ['nullable', 'ulid'],
            'batch_number' => ['nullable', 'string', 'max:64'],
            'quality_status' => ['nullable', Rule::in(['released', 'quarantined', 'rejected'])],
            'expiring_before' => ['nullable', 'date'],
        ]);

        $page = Batch::query()
            ->when($filters['product_id'] ?? null, fn ($q, $v) => $q->where('product_id', $v))
            ->when($filters['batch_number'] ?? null, fn ($q, $v) => $q->where('batch_number', strtoupper($v)))
            ->when($filters['quality_status'] ?? null, fn ($q, $v) => $q->where('quality_status', $v))
            ->when($filters['expiring_before'] ?? null, fn ($q, $v) => $q->where('expiry_date', '<', $v))
            ->orderBy('expiry_date')
            ->paginate(50);

        return response()->json($page->through(fn (Batch $b) => $this->present($b)));
    }

    public function show(string $id, LocationAccess $locations): JsonResponse
    {
        Gate::authorize('inventory.view');
        $batch = Batch::query()->findOrFail($id);

        $balances = $locations->scope($batch->balances()->where('quantity', '!=', 0))
            ->get(['warehouse_id', 'stock_state', 'quantity', 'reserved_quantity']);

        return response()->json(['data' => $this->present($batch) + ['balances' => $balances]]);
    }

    public function changeQuality(Request $request, string $id, string $action, BatchQualityService $quality): JsonResponse
    {
        Gate::authorize('inventory.quality');
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $batch = Batch::query()->findOrFail($id);

        $result = match ($action) {
            'quarantine' => $quality->quarantine($batch, $data['reason']),
            'release' => $quality->release($batch, $data['reason']),
            'reject' => $quality->reject($batch, $data['reason']),
        };

        return response()->json(['data' => $this->present($result)]);
    }

    private function present(Batch $batch): array
    {
        $data = collect($batch->attributesToArray())->except(['organization_id'])->all();
        if (! Gate::allows('inventory.valuation.view')) {
            unset($data['unit_cost']);
        }

        return $data + ['sale_block_reason' => $batch->saleBlockReason()];
    }
}

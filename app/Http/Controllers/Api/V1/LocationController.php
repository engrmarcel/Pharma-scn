<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Billing\Services\EntitlementService;
use App\Domain\Catalogue\Support\Gtin;
use App\Domain\Organizations\Models\Branch;
use App\Domain\Organizations\Models\Warehouse;
use App\Domain\Platform\Services\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Resources\LocationResource;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Branches and warehouses. Both models are tenant-scoped, so lookups by id
 * outside the current organization return 404.
 */
class LocationController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenant,
        private readonly EntitlementService $entitlements,
    ) {}

    public function branches(): AnonymousResourceCollection
    {
        Gate::authorize('locations.view');

        return LocationResource::collection(Branch::query()->orderBy('code')->get());
    }

    public function storeBranch(Request $request): JsonResponse
    {
        Gate::authorize('locations.manage');

        $data = $request->validate($this->branchRules() + [
            'code' => ['required', 'string', 'max:32', 'alpha_dash', Rule::unique('branches')->where('organization_id', $this->tenant->id())],
        ]);
        $branch = $this->entitlements->guard($this->tenant->organization(), 'branches', fn () => Branch::query()->create($data));
        $this->audit->record('branch.created', $branch, new: $data);

        return LocationResource::make($branch)->response()->setStatusCode(201);
    }

    public function updateBranch(Request $request, Branch $branch): LocationResource
    {
        Gate::authorize('locations.manage');

        $original = $branch->getAttributes();
        $branch->fill($request->validate($this->branchRules(partial: true)))->save();
        [$old, $new] = $this->audit->diff($branch, $original);
        $this->audit->record('branch.updated', $branch, $old, $new);

        return LocationResource::make($branch);
    }

    public function warehouses(): AnonymousResourceCollection
    {
        Gate::authorize('locations.view');

        return LocationResource::collection(Warehouse::query()->orderBy('code')->get());
    }

    public function storeWarehouse(Request $request): JsonResponse
    {
        Gate::authorize('locations.manage');

        $tenantId = $this->tenant->id();
        $data = $request->validate($this->warehouseRules($tenantId) + [
            'code' => ['required', 'string', 'max:32', 'alpha_dash', Rule::unique('warehouses')->where('organization_id', $tenantId)],
        ]);
        $warehouse = $this->entitlements->guard($this->tenant->organization(), 'warehouses', fn () => Warehouse::query()->create($data));
        $this->audit->record('warehouse.created', $warehouse, new: $data);

        return LocationResource::make($warehouse)->response()->setStatusCode(201);
    }

    public function updateWarehouse(Request $request, Warehouse $warehouse): LocationResource
    {
        Gate::authorize('locations.manage');

        $original = $warehouse->getAttributes();
        $warehouse->fill($request->validate($this->warehouseRules($warehouse->organization_id, partial: true)))->save();
        [$old, $new] = $this->audit->diff($warehouse, $original);
        $this->audit->record('warehouse.updated', $warehouse, $old, $new);

        return LocationResource::make($warehouse);
    }

    private function branchRules(bool $partial = false): array
    {
        return [
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
            'type' => ['sometimes', Rule::in(['head_office', 'branch', 'retail_outlet', 'department'])],
            'address_line1' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state_region' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:32'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ];
    }

    private function warehouseRules(string $tenantId, bool $partial = false): array
    {
        return [
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
            // The branch must belong to the same organization.
            'branch_id' => ['nullable', 'ulid', Rule::exists('branches', 'id')->where('organization_id', $tenantId)],
            'type' => ['sometimes', Rule::in(['general', 'cold_room', 'quarantine', 'bonded', 'retail_store'])],
            'address_line1' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            // GS1 Global Location Number of this location (NAFDAC traceability regulations, reg. 11).
            'gln' => ['nullable', 'digits:13', fn ($attribute, $value, $fail) => Gtin::isValid((string) $value) || $fail('The GLN check digit is invalid.')],
        ];
    }
}

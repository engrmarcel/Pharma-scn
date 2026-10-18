<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalogue\Enums\ProductStatus;
use App\Domain\Catalogue\Models\OrganizationProduct;
use App\Domain\Platform\Services\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrganizationProductResource;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * The organization's own catalogue: its SKU, prices and reorder settings for
 * canonical products. Tenant-scoped end to end.
 */
class OrganizationProductController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('tenant_products.view');

        $q = $request->validate(['q' => ['nullable', 'string', 'max:100']])['q'] ?? null;

        return OrganizationProductResource::collection(
            OrganizationProduct::query()
                ->with('product')
                ->when($q, fn ($query) => $query->where(fn ($w) => $w
                    ->where('sku', 'like', "%{$q}%")
                    ->orWhere('local_name', 'like', "%{$q}%")
                    ->orWhereHas('product', fn ($p) => $p->search($q))))
                ->orderBy('sku')
                ->paginate(50)
        );
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('tenant_products.manage');

        $data = $request->validate($this->rules() + [
            'product_id' => ['required', 'ulid',
                Rule::exists('products', 'id')->whereNot('status', ProductStatus::UnderReview->value),
                Rule::unique('organization_products')->where('organization_id', $this->tenant->id())],
            'sku' => ['required', 'string', 'max:64', Rule::unique('organization_products')->where('organization_id', $this->tenant->id())],
        ]);
        $data['currency_code'] ??= $this->tenant->organization()->country->currency_code;

        $item = OrganizationProduct::query()->create($data);
        $this->audit->record('organization_product.created', $item, new: $data);

        return OrganizationProductResource::make($item->load('product'))->response()->setStatusCode(201);
    }

    public function update(Request $request, OrganizationProduct $item): OrganizationProductResource
    {
        Gate::authorize('tenant_products.manage');

        $data = $request->validate($this->rules() + [
            'sku' => ['sometimes', 'string', 'max:64', Rule::unique('organization_products')->where('organization_id', $this->tenant->id())->ignore($item->id)],
        ]);

        $original = $item->getAttributes();
        $item->fill($data)->save();
        [$old, $new] = $this->audit->diff($item, $original);
        if ($new !== []) {
            $this->audit->record('organization_product.updated', $item, $old, $new);
        }

        return OrganizationProductResource::make($item->load('product'));
    }

    private function rules(): array
    {
        $money = ['nullable', 'decimal:0,4', 'min:0', 'max:999999999999999'];

        return [
            'local_name' => ['nullable', 'string', 'max:255'],
            'currency_code' => ['sometimes', 'string', 'size:3', 'alpha'],
            'default_purchase_cost' => $money,
            'default_selling_price' => $money,
            'tax_category_code' => ['nullable', 'string', 'max:32'],
            // Must be one of this organization's own suppliers.
            'preferred_supplier_id' => ['nullable', 'ulid', Rule::exists('suppliers', 'id')->where('organization_id', $this->tenant->id())],
            'reorder_level' => ['nullable', 'decimal:0,4', 'min:0'],
            'reorder_quantity' => ['nullable', 'decimal:0,4', 'min:0'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ];
    }
}

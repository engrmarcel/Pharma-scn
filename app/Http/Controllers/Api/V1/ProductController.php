<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalogue\Enums\ProductStatus;
use App\Domain\Catalogue\Models\Product;
use App\Domain\Catalogue\Models\RegulatoryIdentifier;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Http\Resources\RegulatoryIdentifierResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Read access to the shared canonical catalogue for any authenticated user.
 * Products still under review are visible only to catalogue staff.
 */
class ProductController extends Controller
{
    private const RELATIONS = ['brand', 'manufacturer', 'ingredients', 'packageLevels', 'identifiers', 'classifications', 'regulatoryIdentifiers.authority'];

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'dosage_form' => ['nullable', 'string'],
            'manufacturer_id' => ['nullable', 'ulid'],
            'ingredient' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(ProductStatus::class)],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
            'sort' => ['nullable', Rule::in(['display_name', '-display_name', 'updated_at', '-updated_at'])],
        ]);

        $sort = $filters['sort'] ?? 'display_name';

        $products = $this->visible($request)
            ->search($filters['q'] ?? null)
            ->when($filters['dosage_form'] ?? null, fn ($q, $v) => $q->where('dosage_form_code', $v))
            ->when($filters['manufacturer_id'] ?? null, fn ($q, $v) => $q->where('manufacturer_id', $v))
            ->when($filters['ingredient'] ?? null, fn ($q, $v) => $q->whereHas('ingredients', fn ($i) => $i->where('name', 'like', '%'.$v.'%')))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->with(['brand', 'manufacturer'])
            ->orderBy(ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc')
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString();

        return ProductResource::collection($products);
    }

    public function show(Request $request, string $product): ProductResource
    {
        return ProductResource::make($this->visible($request)->with(self::RELATIONS)->findOrFail($product));
    }

    /** Look up who has declared a regulatory identifier, e.g. a NAFDAC number printed on a pack. */
    public function lookupRegulatoryIdentifier(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'authority' => ['required', 'string', 'exists:regulatory_authorities,code'],
            'value' => ['required', 'string', 'max:64'],
        ]);

        $matches = RegulatoryIdentifier::query()
            ->where('subject_type', 'product')
            ->where('value', RegulatoryIdentifier::normalizeValue($data['value']))
            ->whereHas('authority', fn ($q) => $q->where('code', $data['authority']))
            ->with('authority')
            ->limit(20)
            ->get();

        return RegulatoryIdentifierResource::collection($matches)->additional([
            'meta' => ['product_ids' => $matches->pluck('subject_id')->unique()->values()],
        ]);
    }

    private function visible(Request $request)
    {
        $staff = Gate::forUser($request->user())->any(['catalogue.manage', 'catalogue.review']);

        return Product::query()->when(! $staff, fn ($q) => $q->where('status', '!=', ProductStatus::UnderReview->value));
    }
}

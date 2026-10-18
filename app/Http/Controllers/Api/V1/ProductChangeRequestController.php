<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalogue\Models\Product;
use App\Domain\Catalogue\Models\ProductChangeRequest;
use App\Domain\Catalogue\Services\ProductChangeRequestService;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProductChangeRequestResource;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/** An organization proposing additions or corrections to the shared catalogue. */
class ProductChangeRequestController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('catalogue.propose');

        return ProductChangeRequestResource::collection(ProductChangeRequest::query()->latest()->paginate(25));
    }

    public function store(Request $request, ProductChangeRequestService $service, TenantContext $tenant): JsonResponse
    {
        Gate::authorize('catalogue.propose');

        $data = $request->validate([
            'product_id' => ['nullable', 'ulid'],
            'base_version' => ['nullable', 'integer', 'min:1', 'required_with:product_id'],
            'payload' => ['required', 'array'],
        ]);

        $product = isset($data['product_id']) ? Product::query()->findOrFail($data['product_id']) : null;
        $changeRequest = $service->submit($tenant->organization(), $request->user(), $data['payload'], $product, $data['base_version'] ?? null);

        return ProductChangeRequestResource::make($changeRequest)->response()->setStatusCode(201);
    }
}

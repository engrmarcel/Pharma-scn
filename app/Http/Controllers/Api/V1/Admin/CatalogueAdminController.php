<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Catalogue\Models\Product;
use App\Domain\Catalogue\Models\ProductChangeRequest;
use App\Domain\Catalogue\Models\RegulatoryIdentifier;
use App\Domain\Catalogue\Services\ProductCatalogueService;
use App\Domain\Catalogue\Services\ProductChangeRequestService;
use App\Domain\Catalogue\Services\RegulatoryIdentifierService;
use App\Domain\Catalogue\Support\ProductRules;
use App\Domain\Catalogue\Support\RegulatoryIdentifierRules;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProductChangeRequestResource;
use App\Http\Resources\ProductResource;
use App\Http\Resources\RegulatoryIdentifierResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class CatalogueAdminController extends Controller
{
    private const RELATIONS = ['brand', 'manufacturer', 'ingredients', 'packageLevels', 'identifiers', 'classifications', 'regulatoryIdentifiers.authority'];

    public function storeProduct(Request $request, ProductCatalogueService $catalogue): JsonResponse
    {
        Gate::authorize('catalogue.manage');

        $product = $catalogue->create($request->validate(ProductRules::rules()), $request->user());

        return ProductResource::make($product->load(self::RELATIONS))->response()->setStatusCode(201);
    }

    public function updateProduct(Request $request, Product $product, ProductCatalogueService $catalogue): ProductResource
    {
        Gate::authorize('catalogue.manage');

        $data = $request->validate(ProductRules::rules(partial: true) + ['version' => ['required', 'integer', 'min:1']]);
        $version = $data['version'];
        unset($data['version']);

        return ProductResource::make($catalogue->update($product, $data, $version, $request->user())->load(self::RELATIONS));
    }

    public function storeRegulatoryIdentifier(Request $request, Product $product, RegulatoryIdentifierService $service): JsonResponse
    {
        Gate::authorize('catalogue.manage');

        $identifier = $service->attach($product, $request->validate(RegulatoryIdentifierRules::rules()), $request->user());

        return RegulatoryIdentifierResource::make($identifier->load('authority'))->response()->setStatusCode(201);
    }

    public function verifyRegulatoryIdentifier(Request $request, RegulatoryIdentifier $identifier, RegulatoryIdentifierService $service): JsonResponse
    {
        Gate::authorize('regulatory.verify');

        $data = $request->validate([
            'method' => ['required', Rule::in(['manual_document_review', 'official_portal_lookup', 'regulator_api', 'other'])],
            'result' => ['required', Rule::in(['verified', 'failed', 'inconclusive', 'expired', 'revoked'])],
            'source' => ['nullable', 'string', 'max:255'],
            'notes' => ['required_unless:result,verified', 'nullable', 'string', 'max:5000'],
            'evidence_attachment_id' => ['nullable', 'ulid', 'exists:attachments,id'],
            'verified_at' => ['nullable', 'date', 'before_or_equal:now'],
        ]);

        $service->recordVerification($identifier, $data, $request->user());

        return RegulatoryIdentifierResource::make($identifier->fresh()->load(['authority', 'verifications']))->response()->setStatusCode(201);
    }

    public function changeRequests(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('catalogue.review');

        $status = $request->validate(['status' => ['nullable', Rule::in(['pending', 'approved', 'rejected', 'withdrawn'])]])['status'] ?? 'pending';

        return ProductChangeRequestResource::collection(
            ProductChangeRequest::acrossTenants()->where('status', $status)->oldest()->paginate(25)
        );
    }

    public function approveChangeRequest(Request $request, string $changeRequest, ProductChangeRequestService $service): ProductResource
    {
        Gate::authorize('catalogue.review');

        $data = $request->validate(['notes' => ['nullable', 'string', 'max:5000']]);
        $product = $service->approve(ProductChangeRequest::acrossTenants()->findOrFail($changeRequest), $request->user(), $data['notes'] ?? null);

        return ProductResource::make($product->load(self::RELATIONS));
    }

    public function rejectChangeRequest(Request $request, string $changeRequest, ProductChangeRequestService $service): ProductChangeRequestResource
    {
        Gate::authorize('catalogue.review');

        $data = $request->validate(['notes' => ['required', 'string', 'max:5000']]);

        return ProductChangeRequestResource::make($service->reject(ProductChangeRequest::acrossTenants()->findOrFail($changeRequest), $request->user(), $data['notes']));
    }
}

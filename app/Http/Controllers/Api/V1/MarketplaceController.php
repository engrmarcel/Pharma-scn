<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Marketplace\Models\MarketplaceListing;
use App\Domain\Marketplace\Models\MarketplaceProfile;
use App\Domain\Marketplace\Models\TradingRelationship;
use App\Domain\Marketplace\Services\ListingService;
use App\Domain\Marketplace\Services\MarketplaceAccess;
use App\Domain\Marketplace\Services\MarketplaceDiscovery;
use App\Domain\Marketplace\Services\RelationshipService;
use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Organizations\Models\Organization;
use App\Http\Controllers\Controller;
use App\Support\Exceptions\BusinessRuleViolation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** B2B marketplace: profiles, listings, discovery and trading relationships (spec §14, Phase 4a). */
class MarketplaceController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly MarketplaceAccess $access,
    ) {}

    // ---- Supplier side: profile and listings -------------------------------------------

    public function profile(): JsonResponse
    {
        Gate::authorize('marketplace.listings.manage');

        return response()->json(['data' => MarketplaceProfile::query()->first()]);
    }

    public function saveProfile(Request $request, ListingService $listings): JsonResponse
    {
        Gate::authorize('marketplace.listings.manage');
        $data = $request->validate([
            'listed' => ['sometimes', 'boolean'],
            'headline' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'regions_served' => ['nullable', 'array', 'max:60'],
            'regions_served.*' => ['string', 'max:100'],
            'delivery_options' => ['nullable', 'array'],
            'delivery_options.*' => [Rule::in(MarketplaceProfile::DELIVERY_OPTIONS)],
            'minimum_order_value' => ['nullable', 'decimal:0,4', 'min:0'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:32'],
        ]);

        return response()->json(['data' => $listings->saveProfile($data)]);
    }

    public function listings(Request $request): JsonResponse
    {
        Gate::authorize('marketplace.listings.manage');

        return response()->json(MarketplaceListing::query()->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))->latest()->paginate(100));
    }

    public function storeListing(Request $request, ListingService $listings): JsonResponse
    {
        Gate::authorize('marketplace.listings.manage');
        $data = $request->validate($this->listingRules() + [
            'product_id' => ['required', 'ulid', Rule::exists('organization_products', 'product_id')->where('organization_id', $this->tenant->id()),
                Rule::unique('marketplace_listings')->where('organization_id', $this->tenant->id())->where('package_level_code', $request->input('package_level_code'))],
            'package_level_code' => ['required', 'string', 'max:32'],
            'unit_price' => ['required', 'decimal:0,4', 'min:0'],
        ]);

        return response()->json(['data' => $listings->save(null, $data)], 201);
    }

    public function updateListing(Request $request, string $id, ListingService $listings): JsonResponse
    {
        Gate::authorize('marketplace.listings.manage');
        $listing = MarketplaceListing::query()->findOrFail($id);

        return response()->json(['data' => $listings->save($listing, $request->validate($this->listingRules() + ['unit_price' => ['sometimes', 'decimal:0,4', 'min:0']]))]);
    }

    // ---- Buyer side: discovery -------------------------------------------------------------

    public function discover(Request $request, MarketplaceDiscovery $discovery): JsonResponse
    {
        Gate::authorize('marketplace.browse');
        $this->access->assertParticipates($this->tenant->organization());
        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'ingredient' => ['nullable', 'string', 'max:100'],
            'strength' => ['nullable', 'string', 'max:50'],
            'dosage_form' => ['nullable', 'string', 'max:32'],
            'pack' => ['nullable', 'string', 'max:50'],
            'product_id' => ['nullable', 'ulid'],
            'supplier' => ['nullable', 'string', 'max:100'],
            'supplier_type' => ['nullable', Rule::enum(OrganizationType::class)],
            'country' => ['nullable', 'string', 'size:2'],
            'state' => ['nullable', 'string', 'max:100'],
            'delivery' => ['nullable', Rule::in(MarketplaceProfile::DELIVERY_OPTIONS)],
            'verified_only' => ['nullable', 'boolean'],
            'available_only' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        return response()->json($discovery->search($this->tenant->organization(), $f, (int) ($f['page'] ?? 1)));
    }

    public function supplier(string $organizationId): JsonResponse
    {
        Gate::authorize('marketplace.browse');
        $supplier = Organization::query()->findOrFail($organizationId);
        $profile = MarketplaceProfile::acrossTenants()->where('organization_id', $supplier->id)->where('listed', true)->first();
        if ($profile === null || ! $this->access->participates($supplier)) {
            throw new BusinessRuleViolation('supplier_not_listed', 'This supplier is not on the marketplace.', 404);
        }
        $buyer = $this->tenant->organization();

        return response()->json(['data' => [
            'id' => $supplier->id, 'name' => $supplier->name, 'type' => $supplier->type->value, 'country_code' => $supplier->country_code,
            'state_region' => $supplier->state_region, 'city' => $supplier->city,
            'verification' => MarketplaceAccess::verificationLabel($supplier),
            'profile' => $profile->only(['headline', 'description', 'regions_served', 'delivery_options', 'minimum_order_value', 'currency_code', 'contact_email', 'contact_phone']),
            'active_listings' => MarketplaceListing::acrossTenants()->where('organization_id', $supplier->id)->where('status', 'active')->count(),
            'you_may_trade' => $this->access->tradeBlock($buyer, $supplier) === null,
            'trade_block' => $this->access->tradeBlock($buyer, $supplier)['message'] ?? null,
            'relationship' => TradingRelationship::query()->where('buyer_organization_id', $buyer->id)->where('supplier_organization_id', $supplier->id)->value('status'),
        ]]);
    }

    // ---- Relationships ---------------------------------------------------------------------

    public function relationships(Request $request): JsonResponse
    {
        abort_unless(Gate::allows('marketplace.relationships.manage') || Gate::allows('marketplace.browse'), 403);
        $f = $request->validate(['as' => ['nullable', Rule::in(['buyer', 'supplier'])], 'status' => ['nullable', Rule::in(['requested', 'approved', 'rejected', 'suspended'])]]);
        $org = $this->tenant->id();

        $page = TradingRelationship::query()->with(['buyer:id,name,type,verification_status', 'supplier:id,name,type,verification_status'])
            ->when(($f['as'] ?? null) === 'buyer', fn ($q) => $q->where('buyer_organization_id', $org))
            ->when(($f['as'] ?? null) === 'supplier', fn ($q) => $q->where('supplier_organization_id', $org))
            ->when(! isset($f['as']), fn ($q) => $q->involving($org))
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->latest()->paginate(50);

        return response()->json($page->through(fn (TradingRelationship $r) => $this->presentRelationship($r)));
    }

    public function requestRelationship(Request $request, RelationshipService $relationships): JsonResponse
    {
        Gate::authorize('marketplace.relationships.manage');
        $data = $request->validate(['supplier_organization_id' => ['required', 'ulid', 'exists:organizations,id'], 'message' => ['nullable', 'string', 'max:2000']]);

        return response()->json(['data' => $this->presentRelationship($relationships->request(Organization::query()->findOrFail($data['supplier_organization_id']), $data['message'] ?? null))], 201);
    }

    public function decideRelationship(Request $request, string $id, string $decision, RelationshipService $relationships): JsonResponse
    {
        Gate::authorize('marketplace.relationships.manage');
        $data = $request->validate(['notes' => [$decision === 'reject' ? 'required' : 'nullable', 'string', 'max:2000'], 'payment_terms_days' => ['nullable', 'integer', 'between:0,365']]);
        $relationship = TradingRelationship::query()->involving($this->tenant->id())->findOrFail($id);

        return response()->json(['data' => $this->presentRelationship($relationships->decide($relationship, $decision === 'approve', $data['notes'] ?? null, (int) ($data['payment_terms_days'] ?? 0)))]);
    }

    public function suspendRelationship(Request $request, string $id, RelationshipService $relationships): JsonResponse
    {
        Gate::authorize('marketplace.relationships.manage');
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        return response()->json(['data' => $this->presentRelationship($relationships->suspend(TradingRelationship::query()->involving($this->tenant->id())->findOrFail($id), $data['reason']))]);
    }

    private function presentRelationship(TradingRelationship $r): array
    {
        $r->loadMissing(['buyer', 'supplier']);
        $mine = $r->buyer_organization_id === $this->tenant->id() ? 'buyer' : 'supplier';

        return $r->only(['id', 'status', 'request_message', 'decision_notes', 'decided_at', 'payment_terms_days', 'created_at']) + [
            'your_side' => $mine,
            'buyer' => ['id' => $r->buyer->id, 'name' => $r->buyer->name, 'type' => $r->buyer->type->value, 'verification' => MarketplaceAccess::verificationLabel($r->buyer)],
            'supplier' => ['id' => $r->supplier->id, 'name' => $r->supplier->name, 'type' => $r->supplier->type->value, 'verification' => MarketplaceAccess::verificationLabel($r->supplier)],
            // Only this side's own ERP party is revealed.
            'erp_party_id' => $mine === 'buyer' ? $r->buyer_supplier_id : $r->supplier_customer_id,
        ];
    }

    private function listingRules(): array
    {
        return [
            'minimum_order_quantity' => ['nullable', 'decimal:0,4', 'gt:0'],
            'availability_mode' => ['sometimes', Rule::in(['live', 'declared'])],
            'declared_availability' => ['nullable', 'required_if:availability_mode,declared', Rule::in(MarketplaceListing::DECLARED)],
            'warehouse_id' => ['nullable', 'ulid', Rule::exists('warehouses', 'id')->where('organization_id', $this->tenant->id())],
            'lead_time_days' => ['nullable', 'integer', 'between:0,365'],
            'allowed_buyer_types' => ['nullable', 'array'],
            'allowed_buyer_types.*' => [Rule::enum(OrganizationType::class)],
            'status' => ['sometimes', Rule::in(['active', 'paused'])],
        ];
    }
}

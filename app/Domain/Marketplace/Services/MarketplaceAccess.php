<?php

namespace App\Domain\Marketplace\Services;

use App\Domain\Billing\Services\EntitlementService;
use App\Domain\Organizations\Enums\VerificationStatus;
use App\Domain\Organizations\Models\Organization;
use App\Support\Exceptions\BusinessRuleViolation;
use Illuminate\Support\Facades\DB;

/**
 * Who may take part and who may trade what with whom (spec §14.3).
 *
 *  - Both organizations must have the marketplace module enabled and entitled, and be active.
 *  - The buyer's type must be allowed to buy from the supplier's type
 *    (config marketplace.buyer_types_by_supplier_type; a listing may narrow it).
 *  - Restricted products (prescription-only, controlled) need both parties to have
 *    passed platform verification and a buyer of a licensed type for that class.
 *
 * Platform verification is the platform's own review of submitted documents. It
 * is labelled as such everywhere and never presented as a regulator's approval.
 */
class MarketplaceAccess
{
    private array $participation = [];

    public function __construct(private readonly EntitlementService $entitlements) {}

    public function participates(Organization $organization): bool
    {
        return $this->participation[$organization->id] ??= $organization->isActive()
            && $organization->hasModule('marketplace')
            && $this->entitlements->allows($organization, 'module.marketplace');
    }

    public function assertParticipates(Organization $organization, string $who = 'Your organization'): void
    {
        if (! $this->participates($organization)) {
            throw new BusinessRuleViolation('marketplace_not_available', "{$who} does not take part in the marketplace.", 403);
        }
    }

    /** @return array{code: string, message: string}|null null when the pair may trade */
    public function tradeBlock(Organization $buyer, Organization $supplier): ?array
    {
        if ($buyer->id === $supplier->id) {
            return ['code' => 'self_trade', 'message' => 'An organization cannot trade with itself.'];
        }
        $allowed = config('marketplace.buyer_types_by_supplier_type')[$supplier->type->value] ?? null;
        if ($allowed === null) {
            return ['code' => 'supplier_type_cannot_sell', 'message' => "A {$supplier->type->label()} cannot sell on the marketplace."];
        }
        if (! in_array($buyer->type->value, $allowed, true)) {
            return ['code' => 'buyer_type_not_allowed', 'message' => "A {$buyer->type->label()} cannot buy from a {$supplier->type->label()}."];
        }

        return null;
    }

    public function classification(string $productId, string $countryCode): ?string
    {
        $today = business_today()->toDateString();

        return DB::table('product_classifications')->where('product_id', $productId)->where('country_code', $countryCode)
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $today))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $today))
            ->orderByDesc('effective_from')->value('classification');
    }

    public function isRestricted(?string $classification): bool
    {
        return in_array($classification, config('marketplace.restricted_classifications'), true);
    }

    /**
     * Whether this buyer may obtain this product from this supplier.
     *
     * @return array{code: string, message: string}|null
     */
    public function productBlock(Organization $buyer, Organization $supplier, string $productId, ?array $listingBuyerTypes = null, ?string $classification = null): ?array
    {
        if ($block = $this->tradeBlock($buyer, $supplier)) {
            return $block;
        }
        if ($listingBuyerTypes && ! in_array($buyer->type->value, $listingBuyerTypes, true)) {
            return ['code' => 'buyer_type_not_allowed_for_listing', 'message' => 'The supplier offers this listing only to other kinds of buyers.'];
        }

        $classification ??= $this->classification($productId, $supplier->country_code);
        if (! $this->isRestricted($classification)) {
            return null;
        }
        if ($supplier->verification_status !== VerificationStatus::Verified) {
            return ['code' => 'supplier_not_verified', 'message' => 'Restricted products can only be traded by suppliers that have passed platform verification.'];
        }
        if ($buyer->verification_status !== VerificationStatus::Verified) {
            return ['code' => 'buyer_not_verified', 'message' => 'Your organization must pass platform verification before buying restricted products.'];
        }
        if (! in_array($buyer->type->value, config("marketplace.restricted_buyer_types.{$classification}", []), true)) {
            return ['code' => 'buyer_not_licensed_type', 'message' => "A {$buyer->type->label()} may not buy {$classification} products here."];
        }

        return null;
    }

    public function assertMayObtain(Organization $buyer, Organization $supplier, string $productId, ?array $listingBuyerTypes = null): void
    {
        if ($block = $this->productBlock($buyer, $supplier, $productId, $listingBuyerTypes)) {
            throw new BusinessRuleViolation($block['code'], $block['message'], 403, ['product_id' => $productId]);
        }
    }

    /** The verification wording used in every response. */
    public static function verificationLabel(Organization $organization): array
    {
        $status = $organization->verification_status->value;

        return [
            'status' => $status,
            'verified_at' => $organization->verified_at?->toIso8601String(),
            'label' => 'Platform verification: '.str_replace('_', ' ', $status).'. This is the platform\'s own document review, not an approval by a regulator.',
        ];
    }
}

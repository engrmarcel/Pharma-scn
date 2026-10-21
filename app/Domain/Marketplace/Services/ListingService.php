<?php

namespace App\Domain\Marketplace\Services;

use App\Domain\Catalogue\Services\UnitConverter;
use App\Domain\Marketplace\Models\MarketplaceListing;
use App\Domain\Marketplace\Models\MarketplaceProfile;
use App\Domain\Organizations\Enums\VerificationStatus;
use App\Domain\Platform\Services\AuditLogger;
use App\Support\Exceptions\BusinessRuleViolation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * A supplier's public profile and product listings. Listings point at the
 * supplier's own catalogue item and package level; restricted products can
 * only be listed by a supplier that has passed platform verification.
 */
class ListingService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly MarketplaceAccess $access,
        private readonly UnitConverter $units,
        private readonly AuditLogger $audit,
    ) {}

    public function saveProfile(array $data): MarketplaceProfile
    {
        $org = $this->tenant->organization();
        $this->access->assertParticipates($org);
        $profile = MarketplaceProfile::query()->firstOrNew([]);
        $before = $profile->exists ? $profile->getAttributes() : null;
        $profile->fill($data + ['currency_code' => $profile->currency_code ?? $org->country->currency_code])->save();
        $this->audit->record($before ? 'marketplace_profile.updated' : 'marketplace_profile.created', $profile, $before ?? [], $data);

        return $profile;
    }

    public function save(?MarketplaceListing $listing, array $data): MarketplaceListing
    {
        $org = $this->tenant->organization();
        $this->access->assertParticipates($org);

        return DB::transaction(function () use ($listing, $data, $org) {
            $productId = $listing?->product_id ?? $data['product_id'];
            $item = $this->units->catalogueItem($productId);
            if ($item->status !== 'active') {
                throw new BusinessRuleViolation('catalogue_item_inactive', 'Activate the product in your catalogue before listing it.', 422);
            }
            $level = $this->units->level($productId, $data['package_level_code'] ?? $listing?->package_level_code);
            if (! $level->is_sellable) {
                throw new BusinessRuleViolation('package_level_not_sellable', "The {$level->name} level is not sellable.", 422);
            }
            if ($this->access->isRestricted($this->access->classification($productId, $org->country_code)) && $org->verification_status !== VerificationStatus::Verified) {
                throw new BusinessRuleViolation('supplier_verification_required', 'Restricted (prescription-only or controlled) products can be listed only after your organization passes platform verification.', 403);
            }

            $mode = $data['availability_mode'] ?? $listing?->availability_mode ?? 'live';
            if ($mode === 'declared' && ! isset($data['declared_availability']) && $listing?->declared_availability === null) {
                throw new BusinessRuleViolation('declared_availability_required', 'State the availability when not showing live stock.', 422);
            }
            if (isset($data['declared_availability'])) {
                $data['declared_at'] = now(); // the date is always the server's, never typed in
            }
            if ($mode === 'live') {
                $data += ['declared_availability' => null, 'declared_at' => null];
            }

            $before = $listing?->getAttributes();
            $listing ??= new MarketplaceListing(['product_id' => $productId, 'currency_code' => $org->country->currency_code]);
            $listing->fill(array_merge($data, ['package_level_code' => $level->code]))->save();
            $this->audit->record($before ? 'marketplace_listing.updated' : 'marketplace_listing.created', $listing, $before ?? [], $data);

            return $listing;
        });
    }
}

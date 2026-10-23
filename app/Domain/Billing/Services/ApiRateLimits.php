<?php

namespace App\Domain\Billing\Services;

use App\Domain\Organizations\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Per-user API rate from the plan's `api_requests_per_minute` entitlement (spec §21.2), for the
 * organization named in the request. Requests without an organization, or for one the user is
 * not an active member of, get the platform default. "Unlimited" still has a platform ceiling.
 * Cached per user and organization for a minute, so a plan change applies within a minute.
 */
class ApiRateLimits
{
    public function __construct(private readonly EntitlementService $entitlements) {}

    public function perMinute(User $user, ?string $organizationId): int
    {
        $default = (int) config('platform.api.default_rate_per_minute', 120);
        if ($organizationId === null || $organizationId === '') {
            return $default;
        }

        return (int) Cache::remember("api-rate:{$organizationId}:{$user->id}", 60, function () use ($user, $organizationId, $default) {
            $organization = Organization::query()->find($organizationId);
            if ($organization === null || ! $organization->memberships()->where('user_id', $user->id)->where('status', 'active')->exists()) {
                return $default;
            }
            $entitlement = $this->entitlements->entitlements($organization)['api_requests_per_minute'] ?? null;
            if ($entitlement === null || ! $entitlement['enabled']) {
                return $default;
            }

            return $entitlement['limit'] === null ? (int) config('platform.api.unlimited_rate_ceiling', 6000) : max(1, $entitlement['limit']);
        });
    }
}

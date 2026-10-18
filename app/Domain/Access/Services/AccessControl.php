<?php

namespace App\Domain\Access\Services;

use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Resolves registry permissions for a user. Scoped per request/job, so the
 * memoized permission sets never outlive a role change by more than one
 * request.
 *
 * Organization-scope permissions are only ever evaluated against the
 * organization in TenantContext — a platform role never grants tenant data.
 */
class AccessControl
{
    /** @var array<string, array<string, true>> */
    private array $cache = [];

    public function __construct(private readonly TenantContext $tenant) {}

    public function isRegistered(string $ability): bool
    {
        return array_key_exists($ability, config('permissions.permissions'));
    }

    public function scopeOf(string $ability): ?string
    {
        // Codes contain dots, so they cannot be used inside a config() dot-path.
        return config('permissions.permissions')[$ability][0] ?? null;
    }

    public function check(User $user, string $ability): bool
    {
        if (! $user->isActive()) {
            return false;
        }

        return match ($this->scopeOf($ability)) {
            'platform' => isset($this->platformPermissions($user)[$ability]),
            'organization' => isset($this->organizationPermissions($user)[$ability]),
            default => false,
        };
    }

    /** @return array<string, true> */
    public function platformPermissions(User $user): array
    {
        return $this->cache['platform:'.$user->id] ??= $this->flip(
            DB::table('platform_role_user')
                ->join('permission_role', 'permission_role.role_id', '=', 'platform_role_user.role_id')
                ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
                ->where('platform_role_user.user_id', $user->id)
                ->where('permissions.scope', 'platform')
                ->pluck('permissions.code')
                ->all()
        );
    }

    /** @return array<string, true> */
    public function organizationPermissions(User $user): array
    {
        $membership = $this->tenant->membership();

        if ($membership === null || $membership->user_id !== $user->id || ! $membership->isActive()) {
            return [];
        }

        return $this->cache['membership:'.$membership->id] ??= $this->flip(
            DB::table('membership_role')
                ->join('roles', 'roles.id', '=', 'membership_role.role_id')
                ->join('permission_role', 'permission_role.role_id', '=', 'roles.id')
                ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
                ->where('membership_role.membership_id', $membership->id)
                ->where('permissions.scope', 'organization')
                // Defence in depth: a custom role must belong to this organization.
                ->where(fn ($q) => $q->whereNull('roles.organization_id')->orWhere('roles.organization_id', $membership->organization_id))
                ->pluck('permissions.code')
                ->all()
        );
    }

    public function flush(): void
    {
        $this->cache = [];
    }

    private function flip(array $codes): array
    {
        return array_fill_keys($codes, true);
    }
}

<?php

namespace App\Domain\Access\Services;

use App\Domain\Organizations\Models\Organization;
use App\Domain\Platform\Models\PlatformSetting;
use App\Domain\Platform\Services\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Effective account-security policy: config/security.php defaults (both
 * controls OFF) overridden by the administrator-saved "security" platform
 * setting, so production can enable them without a redeploy.
 */
class SecurityPolicy
{
    private const CACHE_KEY = 'platform.security_policy';

    public function __construct(private readonly AuditLogger $audit) {}

    /** @return array{mfa_mode: string, email_verification_required: bool} */
    public function current(): array
    {
        $saved = Cache::rememberForever(self::CACHE_KEY, fn () => PlatformSetting::query()->find('security')?->value ?? []);

        return [
            'mfa_mode' => $saved['mfa_mode'] ?? config('security.mfa_mode'),
            'email_verification_required' => (bool) ($saved['email_verification_required'] ?? config('security.email_verification_required')),
        ];
    }

    public function mfaAvailable(): bool
    {
        return $this->current()['mfa_mode'] !== 'off';
    }

    public function emailVerificationRequired(): bool
    {
        return $this->current()['email_verification_required'];
    }

    /**
     * Must this user have two-factor enrolled before using the platform?
     * Organization requirements apply only while acting in that organization.
     */
    public function mfaRequiredFor(User $user, ?Organization $organization = null): bool
    {
        $mode = $this->current()['mfa_mode'];

        return match ($mode) {
            'off' => false,
            'required_for_all' => true,
            'required_for_platform_staff' => $this->isPlatformStaff($user) || $this->organizationRequires($organization),
            default => $this->organizationRequires($organization),
        };
    }

    public function update(array $values, User $actor): array
    {
        $before = $this->current();
        $after = array_merge($before, array_intersect_key($values, $before));

        PlatformSetting::query()->updateOrCreate(['key' => 'security'], ['value' => $after, 'updated_by' => $actor->id]);
        Cache::forget(self::CACHE_KEY);

        $this->audit->record('platform.security_policy_updated', null, $before, $after, organizationId: false);

        return $this->current();
    }

    private function isPlatformStaff(User $user): bool
    {
        return DB::table('platform_role_user')->where('user_id', $user->id)->exists();
    }

    private function organizationRequires(?Organization $organization): bool
    {
        return (bool) data_get($organization?->settings, 'security.require_mfa', false);
    }
}

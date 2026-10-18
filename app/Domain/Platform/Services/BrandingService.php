<?php

namespace App\Domain\Platform\Services;

use App\Domain\Organizations\Models\Organization;
use App\Domain\Platform\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

/**
 * Brand identity is configuration, not code: defaults from config/platform.php,
 * overridden by administrator-saved values, optionally overridden again by an
 * organization for its own documents.
 */
class BrandingService
{
    private const CACHE_KEY = 'platform.branding';

    public function __construct(private readonly AuditLogger $audit) {}

    public function platform(): array
    {
        $saved = Cache::rememberForever(self::CACHE_KEY, fn () => PlatformSetting::query()->find('branding')?->value ?? []);

        return array_merge(config('platform.branding'), array_filter($saved, fn ($v) => $v !== null && $v !== ''));
    }

    public function forOrganization(?Organization $organization): array
    {
        $platform = $this->platform();
        if ($organization === null || empty($organization->branding)) {
            return $platform;
        }

        $overrides = Arr::only($organization->branding, config('platform.tenant_brandable_keys'));

        return array_merge($platform, array_filter($overrides, fn ($v) => $v !== null && $v !== ''));
    }

    public function update(array $values, User $actor): array
    {
        $before = PlatformSetting::query()->find('branding')?->value ?? [];
        $after = array_merge($before, Arr::only($values, array_keys(config('platform.branding'))));

        PlatformSetting::query()->updateOrCreate(['key' => 'branding'], ['value' => $after, 'updated_by' => $actor->id]);
        Cache::forget(self::CACHE_KEY);

        $this->audit->record('platform.branding_updated', null, $before, $after, organizationId: false);

        return $this->platform();
    }
}

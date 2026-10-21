<?php

namespace App\Http\Middleware;

use App\Domain\Sync\Models\Device;
use App\Domain\Sync\Services\DeviceService;
use App\Support\Exceptions\BusinessRuleViolation;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requests made with a device-bound token (spec §13.2):
 *  - stop as soon as the device is revoked (401 device_revoked, telling the app
 *    whether to erase its local data; the token is then deleted);
 *  - may only act for the organization the device was registered to;
 *  - record contact and slide the token's expiry.
 * Ordinary tokens and web sessions pass through unchanged.
 */
class EnsureDeviceActive
{
    public function __construct(private readonly DeviceService $devices) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();
        if (! $token instanceof PersonalAccessToken || ! is_string($token->device_id) || $token->device_id === '') {
            return $next($request);
        }

        $device = Device::acrossTenants()->find($token->device_id);
        if ($device === null || ! $device->isActive()) {
            $wipe = (bool) $device?->wipe_requested;
            $device?->forceFill(['revoke_notified_at' => now()])->saveQuietly();
            $token->delete();

            throw new BusinessRuleViolation('device_revoked', 'This device has been deauthorized. Contact your administrator.', 401, ['wipe' => $wipe]);
        }

        $requested = $request->headers->get(config('platform.tenancy.header'));
        if ($requested !== null && $requested !== $device->organization_id) {
            throw new BusinessRuleViolation('device_organization_mismatch', 'This device is registered to another organization.', 403);
        }

        $request->attributes->set('device', $device);
        $this->devices->touch($device, $token);

        return $next($request);
    }
}

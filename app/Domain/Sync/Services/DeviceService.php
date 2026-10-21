<?php

namespace App\Domain\Sync\Services;

use App\Domain\Platform\Services\AuditLogger;
use App\Domain\Pos\Models\PosTerminal;
use App\Domain\Sync\Models\Device;
use App\Models\User;
use App\Support\Exceptions\BusinessRuleViolation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Device registration and revocation (spec §13.2). A registered device gets
 * its own API token bound to it; revoking the device stops that token at
 * the next request, and can ask the app to erase its local data.
 */
class DeviceService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @return array{device: Device, token: string, expires_at: string} */
    public function register(User $user, array $data): array
    {
        $role = $data['role'];
        if ($role === 'field' && ! Gate::allows('field_force.visits.capture') && ! Gate::allows('field_force.orders.capture')) {
            throw new BusinessRuleViolation('device_role_not_allowed', 'You do not have field-force access in this organization.', 403);
        }
        if ($role === 'pos') {
            if (! Gate::allows('pos.sell')) {
                throw new BusinessRuleViolation('device_role_not_allowed', 'You do not have POS access in this organization.', 403);
            }
            $terminal = PosTerminal::query()->find($data['pos_terminal_id'] ?? null);
            if ($terminal === null || $terminal->status !== 'active') {
                throw new BusinessRuleViolation('pos_terminal_invalid', 'Choose an active POS terminal for this device.', 422);
            }
        }

        return DB::transaction(function () use ($user, $data, $role) {
            $device = Device::query()->create([
                'user_id' => $user->id,
                'name' => $data['name'],
                'platform' => $data['platform'],
                'app_version' => $data['app_version'] ?? null,
                'role' => $role,
                'pos_terminal_id' => $role === 'pos' ? $data['pos_terminal_id'] : null,
                'last_seen_at' => now(),
            ]);

            $expires = now()->addHours(config('sync.device_token_ttl_hours'));
            $token = $user->createToken("device:{$device->id}", ['*'], $expires);
            $token->accessToken->forceFill(['device_id' => $device->id])->save();

            $this->audit->record('device.registered', $device, new: ['name' => $device->name, 'role' => $role, 'platform' => $device->platform]);

            return ['device' => $device, 'token' => $token->plainTextToken, 'expires_at' => $expires->toIso8601String()];
        });
    }

    public function revoke(Device $device, User $actor, string $reason, bool $wipe): Device
    {
        return DB::transaction(function () use ($device, $actor, $reason, $wipe) {
            $locked = Device::query()->lockForUpdate()->findOrFail($device->id);
            if (! $locked->isActive()) {
                throw new BusinessRuleViolation('device_already_revoked', 'This device is already revoked.', 409);
            }
            $locked->forceFill(['status' => 'revoked', 'wipe_requested' => $wipe, 'revoked_at' => now(), 'revoked_by' => $actor->id, 'revoke_reason' => $reason])->save();
            $this->audit->record('device.revoked', $locked, new: ['reason' => $reason, 'wipe' => $wipe]);

            return $locked;
        });
    }

    /**
     * Called on each request made with the device's token: records contact and
     * slides the token's expiry (at most once a minute).
     */
    public function touch(Device $device, PersonalAccessToken $token): void
    {
        if ($device->last_seen_at === null || $device->last_seen_at->lt(now()->subMinute())) {
            $device->forceFill(['last_seen_at' => now()])->saveQuietly();
            $token->forceFill(['expires_at' => now()->addHours(config('sync.device_token_ttl_hours'))])->save();
        }
    }
}

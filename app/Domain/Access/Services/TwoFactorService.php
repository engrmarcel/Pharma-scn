<?php

namespace App\Domain\Access\Services;

use App\Domain\Platform\Services\AuditLogger;
use App\Domain\Platform\Services\BrandingService;
use App\Models\User;
use App\Support\Exceptions\BusinessRuleViolation;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * RFC 6238 TOTP two-factor authentication with single-use recovery codes.
 *
 * The secret is stored with Laravel's encrypted cast; recovery codes are
 * stored only as SHA-256 hashes inside an encrypted column. A TOTP code is
 * accepted at most once (last used time step is recorded).
 */
class TwoFactorService
{
    private const WINDOW = 1; // accept one 30 s step either side for clock drift

    public function __construct(
        private readonly Google2FA $google2fa,
        private readonly SecurityPolicy $policy,
        private readonly AuditLogger $audit,
        private readonly BrandingService $branding,
    ) {}

    public function isEnabled(User $user): bool
    {
        return $user->mfa_enabled_at !== null && $user->mfa_secret !== null;
    }

    /**
     * Start (or restart) enrolment. Replaces any unconfirmed secret.
     *
     * @return array{secret: string, otpauth_uri: string, qr_svg: string}
     */
    public function begin(User $user): array
    {
        $this->assertAvailable();
        if ($this->isEnabled($user)) {
            throw new BusinessRuleViolation('mfa_already_enabled', 'Two-factor authentication is already enabled.', 409);
        }

        $secret = $this->google2fa->generateSecretKey(32);
        $user->forceFill(['mfa_secret' => $secret, 'mfa_enabled_at' => null, 'mfa_recovery_codes' => null])->save();

        $issuer = config('security.mfa_issuer') ?: $this->branding->platform()['display_name'];
        $uri = $this->google2fa->getQRCodeUrl($issuer, $user->email, $secret);

        return ['secret' => $secret, 'otpauth_uri' => $uri, 'qr_svg' => $this->qrSvg($uri)];
    }

    /**
     * Confirm enrolment with a first valid code.
     *
     * @return list<string> plain recovery codes, shown exactly once
     */
    public function confirm(User $user, string $code): array
    {
        $this->assertAvailable();
        if ($user->mfa_secret === null || $this->isEnabled($user)) {
            throw new BusinessRuleViolation('mfa_setup_not_started', 'Start two-factor setup first.', 409);
        }
        if (! $this->checkTotp($user, $code)) {
            throw new BusinessRuleViolation('mfa_code_invalid', 'The authentication code is invalid or expired.', 422);
        }

        $codes = $this->issueRecoveryCodes($user, enable: true);
        $this->audit->record('auth.mfa_enabled', $user, organizationId: false);

        return $codes;
    }

    /**
     * Verify a login challenge: a TOTP code or one unused recovery code.
     */
    public function verifyChallenge(User $user, ?string $code, ?string $recoveryCode): bool
    {
        if ($code !== null && $code !== '' && $this->checkTotp($user, $code)) {
            return true;
        }

        if ($recoveryCode !== null && $recoveryCode !== '' && $this->consumeRecoveryCode($user, $recoveryCode)) {
            $this->audit->record('auth.mfa_recovery_code_used', $user, metadata: ['remaining' => count($this->recoveryHashes($user))], organizationId: false);

            return true;
        }

        return false;
    }

    public function disable(User $user, string $code, ?string $recoveryCode = null): void
    {
        if (! $this->isEnabled($user)) {
            throw new BusinessRuleViolation('mfa_not_enabled', 'Two-factor authentication is not enabled.', 409);
        }
        if ($this->policy->mfaRequiredFor($user)) {
            throw new BusinessRuleViolation('mfa_required_by_policy', 'Two-factor authentication is required for your account and cannot be turned off.', 403);
        }
        if (! $this->verifyChallenge($user, $code, $recoveryCode)) {
            throw new BusinessRuleViolation('mfa_code_invalid', 'The authentication code is invalid or expired.', 422);
        }

        $user->forceFill(['mfa_secret' => null, 'mfa_enabled_at' => null, 'mfa_recovery_codes' => null, 'mfa_last_used_timestep' => null])->save();
        $this->audit->record('auth.mfa_disabled', $user, organizationId: false);
    }

    /** @return list<string> */
    public function regenerateRecoveryCodes(User $user, string $code): array
    {
        if (! $this->isEnabled($user) || ! $this->checkTotp($user, $code)) {
            throw new BusinessRuleViolation('mfa_code_invalid', 'The authentication code is invalid or expired.', 422);
        }

        $codes = $this->issueRecoveryCodes($user, enable: false);
        $this->audit->record('auth.mfa_recovery_codes_regenerated', $user, organizationId: false);

        return $codes;
    }

    /** Platform support reset (e.g. lost device), recorded in the audit log. */
    public function reset(User $user, User $actor, string $reason): void
    {
        $user->forceFill(['mfa_secret' => null, 'mfa_enabled_at' => null, 'mfa_recovery_codes' => null, 'mfa_last_used_timestep' => null])->save();
        $user->tokens()->delete();
        $this->audit->record('auth.mfa_reset_by_platform', $user, metadata: ['reason' => $reason, 'actor_id' => $actor->id], organizationId: false);
    }

    private function checkTotp(User $user, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code);
        if ($user->mfa_secret === null || ! preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        // Atomic replay protection: lock the row, then only accept a step newer than the last one used.
        return DB::transaction(function () use ($user, $code) {
            $locked = User::query()->lockForUpdate()->find($user->id);
            // Passing 0 (never NULL) makes the library return the matched time step as an int.
            $timestep = $this->google2fa->verifyKeyNewer($locked->mfa_secret, $code, (int) ($locked->mfa_last_used_timestep ?? 0), self::WINDOW);

            if (! is_int($timestep)) {
                return false;
            }

            $locked->forceFill(['mfa_last_used_timestep' => $timestep])->save();
            $user->setRawAttributes($locked->getAttributes(), true);

            return true;
        });
    }

    /** @return list<string> */
    private function issueRecoveryCodes(User $user, bool $enable): array
    {
        $codes = [];
        for ($i = 0; $i < config('security.recovery_code_count'); $i++) {
            $codes[] = Str::lower(Str::random(5).'-'.Str::random(5));
        }

        $user->forceFill(array_filter([
            'mfa_recovery_codes' => encrypt(array_map(fn ($c) => hash('sha256', $c), $codes)),
            'mfa_enabled_at' => $enable ? now() : null,
        ], fn ($v) => $v !== null))->save();

        return $codes;
    }

    private function consumeRecoveryCode(User $user, string $code): bool
    {
        return DB::transaction(function () use ($user, $code) {
            $locked = User::query()->lockForUpdate()->find($user->id);
            $hashes = $this->recoveryHashes($locked);
            $hash = hash('sha256', Str::lower(trim($code)));

            foreach ($hashes as $i => $stored) {
                if (hash_equals($stored, $hash)) {
                    unset($hashes[$i]);
                    $locked->forceFill(['mfa_recovery_codes' => encrypt(array_values($hashes))])->save();
                    $user->setRawAttributes($locked->getAttributes(), true);

                    return true;
                }
            }

            return false;
        });
    }

    /** @return list<string> */
    private function recoveryHashes(User $user): array
    {
        return $user->mfa_recovery_codes ? decrypt($user->mfa_recovery_codes) : [];
    }

    private function assertAvailable(): void
    {
        if (! $this->policy->mfaAvailable()) {
            throw new BusinessRuleViolation('mfa_disabled', 'Two-factor authentication is not enabled on this platform.', 403);
        }
    }

    private function qrSvg(string $uri): string
    {
        return (new Writer(new ImageRenderer(new RendererStyle(200, 1), new SvgImageBackEnd)))->writeString($uri);
    }
}

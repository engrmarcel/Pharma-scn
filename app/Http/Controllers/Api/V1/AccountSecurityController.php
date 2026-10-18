<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Access\Services\SecurityPolicy;
use App\Domain\Access\Services\TwoFactorService;
use App\Domain\Platform\Services\AuditLogger;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Exceptions\BusinessRuleViolation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Two-factor enrolment, email verification and the security policy
 * switches. Both controls are OFF by default (config/security.php).
 */
class AccountSecurityController extends Controller
{
    public function __construct(
        private readonly TwoFactorService $twoFactor,
        private readonly SecurityPolicy $policy,
        private readonly AuditLogger $audit,
    ) {}

    public function status(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(['data' => [
            'policy' => $this->policy->current(),
            'email_verified' => $user->email_verified_at !== null,
            'mfa_enabled' => $this->twoFactor->isEnabled($user),
            'mfa_required' => $this->policy->mfaRequiredFor($user),
        ]]);
    }

    public function setupMfa(Request $request): JsonResponse
    {
        $this->confirmPassword($request);

        return response()->json(['data' => $this->twoFactor->begin($request->user())]);
    }

    public function confirmMfa(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:10']]);

        return response()->json(['data' => [
            'mfa_enabled' => true,
            'recovery_codes' => $this->twoFactor->confirm($request->user(), $data['code']),
            'notice' => 'Store these recovery codes securely. They will not be shown again.',
        ]]);
    }

    public function disableMfa(Request $request): JsonResponse
    {
        $this->confirmPassword($request);
        $data = $request->validate([
            'code' => ['nullable', 'required_without:recovery_code', 'string', 'max:10'],
            'recovery_code' => ['nullable', 'string', 'max:20'],
        ]);

        $this->twoFactor->disable($request->user(), (string) ($data['code'] ?? ''), $data['recovery_code'] ?? null);

        return response()->json(null, 204);
    }

    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:10']]);

        return response()->json(['data' => ['recovery_codes' => $this->twoFactor->regenerateRecoveryCodes($request->user(), $data['code'])]]);
    }

    public function resendVerification(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->email_verified_at !== null) {
            throw new BusinessRuleViolation('email_already_verified', 'This email address is already verified.', 409);
        }

        $user->sendEmailVerificationNotification();

        return response()->json(['message' => 'Verification link sent.'], 202);
    }

    /** Signed link from the verification email; the signature is the proof, no session needed. */
    public function verifyEmail(Request $request, int $id, string $hash): JsonResponse|RedirectResponse
    {
        $user = User::query()->findOrFail($id);
        abort_unless(hash_equals(sha1($user->getEmailForVerification()), $hash), 403, 'Invalid verification link.');

        if ($user->email_verified_at === null) {
            $user->markEmailAsVerified();
            $this->audit->record('auth.email_verified', $user, organizationId: false);
        }

        return $request->expectsJson()
            ? response()->json(['message' => 'Email verified.'])
            : redirect()->route('dashboard')->with('status', 'Your email address is verified.');
    }

    public function platformSettings(): JsonResponse
    {
        Gate::authorize('platform.settings.manage');

        return response()->json(['data' => $this->policy->current()]);
    }

    public function updatePlatformSettings(Request $request): JsonResponse
    {
        Gate::authorize('platform.settings.manage');

        $data = $request->validate([
            'mfa_mode' => ['sometimes', Rule::in(config('security.mfa_modes'))],
            'email_verification_required' => ['sometimes', 'boolean'],
        ]);

        // Guard against locking platform staff out: whoever makes two-factor mandatory for staff must be enrolled.
        if (in_array($data['mfa_mode'] ?? null, ['required_for_platform_staff', 'required_for_all'], true) && ! $this->twoFactor->isEnabled($request->user())) {
            throw new BusinessRuleViolation('mfa_enrollment_required', 'Enable two-factor on your own account before making it mandatory.', 409);
        }

        return response()->json(['data' => $this->policy->update($data, $request->user())]);
    }

    public function resetUserMfa(Request $request, User $user): JsonResponse
    {
        Gate::authorize('platform.users.manage');
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        if ($user->is($request->user())) {
            throw new BusinessRuleViolation('cannot_reset_own_mfa', 'Ask another administrator to reset your two-factor.', 403);
        }

        $this->twoFactor->reset($user, $request->user(), $data['reason']);

        return response()->json(['data' => ['id' => $user->id, 'mfa_enabled' => false]]);
    }

    public function updateOrganizationSecurity(Request $request, TenantContext $tenant): JsonResponse
    {
        Gate::authorize('organization.manage');
        $data = $request->validate(['require_mfa' => ['required', 'boolean']]);

        if ($data['require_mfa'] && ! $this->twoFactor->isEnabled($request->user())) {
            throw new BusinessRuleViolation('mfa_enrollment_required', 'Enable two-factor on your own account before requiring it for the organization.', 409);
        }

        $organization = $tenant->organization();
        $before = (bool) data_get($organization->settings, 'security.require_mfa', false);
        $settings = $organization->settings ?? [];
        data_set($settings, 'security.require_mfa', $data['require_mfa']);
        $organization->forceFill(['settings' => $settings])->save();
        $this->audit->record('organization.security_updated', $organization, ['require_mfa' => $before], ['require_mfa' => $data['require_mfa']]);

        return response()->json(['data' => [
            'require_mfa' => $data['require_mfa'],
            // An organization requirement only takes effect while the platform allows two-factor.
            'effective' => $data['require_mfa'] && $this->policy->mfaAvailable(),
        ]]);
    }

    private function confirmPassword(Request $request): void
    {
        $request->validate(['password' => ['required', 'string']]);
        if (! Hash::check($request->input('password'), $request->user()->password)) {
            throw ValidationException::withMessages(['password' => __('auth.password')]);
        }
    }
}

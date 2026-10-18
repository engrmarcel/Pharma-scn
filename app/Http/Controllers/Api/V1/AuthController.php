<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Access\Services\SecurityPolicy;
use App\Domain\Access\Services\TwoFactorService;
use App\Domain\Platform\Services\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Resources\MembershipResource;
use App\Models\User;
use App\Support\Exceptions\BusinessRuleViolation;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SecurityPolicy $policy,
        private readonly TwoFactorService $twoFactor,
    ) {}

    public function issueToken(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:100'],
            'mfa_code' => ['nullable', 'string', 'max:10'],
            'recovery_code' => ['nullable', 'string', 'max:20'],
        ]);

        $user = User::query()->where('email', strtolower($data['email']))->first();

        if ($user === null || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }
        if (! $user->isActive()) {
            throw new BusinessRuleViolation('account_suspended', 'This account is suspended.', 403);
        }

        // Enrolled users are challenged whenever the platform has two-factor switched on.
        if ($this->policy->mfaAvailable() && $this->twoFactor->isEnabled($user)) {
            if (blank($data['mfa_code'] ?? null) && blank($data['recovery_code'] ?? null)) {
                throw new BusinessRuleViolation('mfa_required', 'Enter the code from your authenticator app (or a recovery code).', 401, ['methods' => ['mfa_code', 'recovery_code']]);
            }
            if (! $this->twoFactor->verifyChallenge($user, $data['mfa_code'] ?? null, $data['recovery_code'] ?? null)) {
                $this->audit->record('auth.mfa_challenge_failed', $user, organizationId: false);
                throw new BusinessRuleViolation('mfa_code_invalid', 'The authentication code is invalid or expired.', 401);
            }
        }

        return $this->tokenResponse($user, $data['device_name']);
    }

    /** Self-service sign-up. The account can then register or join organizations. */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:32'],
            'password' => ['required', 'confirmed', PasswordRule::min(10)->letters()->numbers()],
            'device_name' => ['required', 'string', 'max:100'],
        ]);

        $user = User::query()->create([
            'name' => $data['name'],
            'email' => strtolower($data['email']),
            'phone' => $data['phone'] ?? null,
            'password' => $data['password'],
        ]);

        Auth::setUser($user);
        $this->audit->record('auth.registered', $user, organizationId: false);

        if ($this->policy->emailVerificationRequired()) {
            $user->sendEmailVerificationNotification();
        }

        return $this->tokenResponse($user, $data['device_name']);
    }

    private function tokenResponse(User $user, string $deviceName): JsonResponse
    {
        $token = $user->createToken($deviceName, ['*'], now()->addDays(30));
        $user->forceFill(['last_login_at' => now()])->save();

        Auth::setUser($user);
        $this->audit->record('auth.token_issued', $user, metadata: ['device_name' => $deviceName, 'token_id' => $token->accessToken->id], organizationId: false);

        return response()->json([
            'data' => [
                'token' => $token->plainTextToken,
                'token_type' => 'Bearer',
                'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
                // Lets clients route straight to verification / two-factor setup when the policy requires it.
                'email_verified' => $user->email_verified_at !== null,
                'email_verification_required' => $this->policy->emailVerificationRequired(),
                'mfa_enrollment_required' => $this->policy->mfaRequiredFor($user) && ! $this->twoFactor->isEnabled($user),
            ],
        ], 201);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $memberships = $user->activeMemberships()->with(['organization', 'roles'])->get();

        return response()->json(['data' => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'email_verified' => $user->email_verified_at !== null,
            'mfa_enabled' => $user->mfa_enabled_at !== null,
            'platform_roles' => $user->platformRoles()->pluck('code'),
            'memberships' => MembershipResource::collection($memberships),
        ]]);
    }

    public function tokens(Request $request): JsonResponse
    {
        $currentId = $request->user()->currentAccessToken()?->id;

        return response()->json(['data' => $request->user()->tokens()->latest()->get()->map(fn ($t) => [
            'id' => $t->id,
            'name' => $t->name,
            'last_used_at' => $t->last_used_at?->toIso8601String(),
            'expires_at' => $t->expires_at?->toIso8601String(),
            'created_at' => $t->created_at->toIso8601String(),
            'current' => $t->id === $currentId,
        ])]);
    }

    public function revokeToken(Request $request, int $tokenId): JsonResponse
    {
        $deleted = $request->user()->tokens()->whereKey($tokenId)->delete();
        abort_if($deleted === 0, 404);

        $this->audit->record('auth.token_revoked', $request->user(), metadata: ['token_id' => $tokenId], organizationId: false);

        return response()->json(null, 204);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(null, 204);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);
        Password::sendResetLink($request->only('email'));

        // Same response whether or not the account exists.
        return response()->json(['message' => 'If an account exists for that email, a reset link has been sent.']);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(10)->letters()->numbers()],
        ]);

        $status = Password::reset($request->only('email', 'password', 'password_confirmation', 'token'), function (User $user, string $password) {
            $user->forceFill(['password' => $password])->save();
            // A password reset ends every existing API session.
            $user->tokens()->delete();
            event(new PasswordReset($user));
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => __($status)]);
        }

        return response()->json(['message' => __($status)]);
    }
}

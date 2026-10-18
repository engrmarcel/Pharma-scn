<?php

namespace App\Http\Controllers\Api\V1;

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
    public function __construct(private readonly AuditLogger $audit) {}

    public function issueToken(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:100'],
        ]);

        $user = User::query()->where('email', strtolower($data['email']))->first();

        if ($user === null || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }
        if (! $user->isActive()) {
            throw new BusinessRuleViolation('account_suspended', 'This account is suspended.', 403);
        }

        $token = $user->createToken($data['device_name'], ['*'], now()->addDays(30));
        $user->forceFill(['last_login_at' => now()])->save();

        Auth::setUser($user);
        $this->audit->record('auth.token_issued', $user, metadata: ['device_name' => $data['device_name'], 'token_id' => $token->accessToken->id], organizationId: false);

        return response()->json([
            'data' => [
                'token' => $token->plainTextToken,
                'token_type' => 'Bearer',
                'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
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

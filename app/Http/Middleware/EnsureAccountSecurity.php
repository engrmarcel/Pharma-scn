<?php

namespace App\Http\Middleware;

use App\Domain\Access\Services\SecurityPolicy;
use App\Domain\Access\Services\TwoFactorService;
use App\Support\Exceptions\BusinessRuleViolation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the platform security policy (email verification, mandatory
 * two-factor enrolment). Both are OFF by default; see config/security.php.
 *
 * A non-compliant account can still reach the routes needed to become
 * compliant (profile, sign-out, verification and two-factor setup).
 * Organization-level two-factor requirements are applied by ResolveTenant.
 */
class EnsureAccountSecurity
{
    /** Route names reachable before the account is compliant. */
    private const EXEMPT = [
        'api.v1.auth.me', 'api.v1.auth.logout', 'api.v1.auth.mfa.*', 'api.v1.auth.email.*',
        'logout', 'account.security', 'verification.*',
    ];

    public function __construct(
        private readonly SecurityPolicy $policy,
        private readonly TwoFactorService $twoFactor,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $request->routeIs(...self::EXEMPT)) {
            return $next($request);
        }

        if ($this->policy->emailVerificationRequired() && $user->email_verified_at === null) {
            return $this->deny($request, 'email_unverified', 'Verify your email address to continue.');
        }

        if ($this->policy->mfaRequiredFor($user) && ! $this->twoFactor->isEnabled($user)) {
            return $this->deny($request, 'mfa_enrollment_required', 'Set up two-factor authentication to continue.');
        }

        return $next($request);
    }

    private function deny(Request $request, string $code, string $message): Response
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            throw new BusinessRuleViolation($code, $message, 403);
        }

        return redirect()->route('account.security')->with('status', $message);
    }
}

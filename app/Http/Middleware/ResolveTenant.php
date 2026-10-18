<?php

namespace App\Http\Middleware;

use App\Domain\Access\Services\AccessControl;
use App\Domain\Access\Services\SecurityPolicy;
use App\Domain\Access\Services\TwoFactorService;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Support\Exceptions\BusinessRuleViolation;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Establishes the acting organization. API clients send X-Organization-Id;
 * web sessions use the organization chosen in the switcher. Either way the
 * value is only a request: it is honoured solely when the authenticated user
 * holds an active membership in an active organization.
 *
 * Runs before route-model binding (see bootstrap/app.php) so bound
 * tenant-owned models are already scoped.
 */
class ResolveTenant
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AccessControl $access,
        private readonly SecurityPolicy $policy,
        private readonly TwoFactorService $twoFactor,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Long-lived workers (Octane, test runner) reuse the instance; never carry permissions over.
        $this->access->flush();

        $user = $request->user();
        $requested = $request->headers->get(config('platform.tenancy.header'))
            ?? ($request->hasSession() ? $request->session()->get(config('platform.tenancy.session_key')) : null);

        if ($requested === null || $requested === '') {
            throw new BusinessRuleViolation('organization_required', 'Select an organization (send the '.config('platform.tenancy.header').' header).', 400);
        }

        // Same response for malformed, nonexistent and foreign organizations: no enumeration.
        $membership = Str::isUlid($requested) && $user
            ? OrganizationMembership::query()
                ->with('organization')
                ->where('organization_id', $requested)
                ->where('user_id', $user->id)
                ->first()
            : null;

        if ($membership === null || ! $membership->isActive()) {
            throw new BusinessRuleViolation('organization_access_denied', 'You do not have access to this organization.', 403);
        }

        if (! $membership->organization->isActive()) {
            throw new BusinessRuleViolation('organization_suspended', 'This organization is suspended. Contact platform support.', 403);
        }

        // Organization-level two-factor requirement (only effective when the platform policy allows two-factor).
        if ($this->policy->mfaRequiredFor($user, $membership->organization) && ! $this->twoFactor->isEnabled($user)) {
            throw new BusinessRuleViolation('mfa_enrollment_required', 'This organization requires two-factor authentication. Set it up to continue.', 403);
        }

        $this->tenant->set($membership->organization, $membership);

        try {
            return $next($request);
        } finally {
            $this->tenant->clear();
        }
    }
}

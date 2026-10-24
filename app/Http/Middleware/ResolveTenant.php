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

    /**
     * "tenant:optional" (pages such as the dashboard): without a valid selection the
     * request simply continues with no organization instead of failing.
     */
    public function handle(Request $request, Closure $next, ?string $mode = null): Response
    {
        // Long-lived workers (Octane, test runner) reuse the instance; never carry permissions over.
        $this->access->flush();

        try {
            $membership = $this->resolve($request);
        } catch (BusinessRuleViolation $e) {
            if ($mode === 'optional') {
                return $next($request);
            }
            throw $e;
        }

        $this->tenant->set($membership->organization, $membership);

        // A Livewire update re-applies this middleware as a stand-alone pipeline before it runs the
        // component, so the context must outlive $next here, or every action on a tenant screen runs
        // without an organization (403). It still ends with the request: the context is request-scoped.
        if ($request->hasHeader('X-Livewire')) {
            return $next($request);
        }

        try {
            return $next($request);
        } finally {
            $this->tenant->clear();
        }
    }

    private function resolve(Request $request): OrganizationMembership
    {
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

        // Regional deployments: an organization's data lives in one region; a deployment serves only its own.
        $region = $membership->organization->data_region;
        if (config('platform.regions.enforce') && $region !== config('platform.regions.current')) {
            throw new BusinessRuleViolation('organization_in_other_region', 'This organization is hosted in another region.', 421,
                array_filter(['region' => $region, 'url' => config("platform.regions.urls.{$region}")]));
        }

        // Organization-level two-factor requirement (only effective when the platform policy allows two-factor).
        if ($this->policy->mfaRequiredFor($user, $membership->organization) && ! $this->twoFactor->isEnabled($user)) {
            throw new BusinessRuleViolation('mfa_enrollment_required', 'This organization requires two-factor authentication. Set it up to continue.', 403);
        }

        return $membership;
    }
}

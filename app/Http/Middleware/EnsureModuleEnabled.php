<?php

namespace App\Http\Middleware;

use App\Domain\Billing\Services\EntitlementService;
use App\Support\Exceptions\BusinessRuleViolation;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Usage: ->middleware('module:pos'). Must run after ResolveTenant.
 * A module is usable only when platform operations enabled it for the
 * organization AND the organization's plan entitles it.
 */
class EnsureModuleEnabled
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly EntitlementService $entitlements,
    ) {}

    public function handle(Request $request, Closure $next, string $module): Response
    {
        $organization = $this->tenant->organization();

        if (! $organization?->hasModule($module)) {
            throw new BusinessRuleViolation('module_not_enabled', "The {$module} module is not enabled for this organization.", 403);
        }

        if (! $this->entitlements->allows($organization, "module.{$module}")) {
            throw new BusinessRuleViolation('module_not_entitled', "The organization's subscription does not include the {$module} module.", 403);
        }

        return $next($request);
    }
}

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

    /**
     * "module:procurement|inventory" passes when ANY listed module is both enabled and entitled.
     */
    public function handle(Request $request, Closure $next, string $modules): Response
    {
        $organization = $this->tenant->organization();
        $candidates = explode('|', $modules);

        // One query for all candidates (was one per module; Phase 10 load test).
        $on = $organization ? $organization->modules()->where('enabled', true)->whereIn('module', $candidates)->pluck('module')->all() : [];
        $enabled = array_values(array_intersect($candidates, $on));
        if ($enabled === []) {
            throw new BusinessRuleViolation('module_not_enabled', "The {$modules} module is not enabled for this organization.", 403);
        }

        foreach ($enabled as $module) {
            if ($this->entitlements->allows($organization, "module.{$module}")) {
                return $next($request);
            }
        }

        throw new BusinessRuleViolation('module_not_entitled', "The organization's subscription does not include the {$modules} module.", 403);
    }
}

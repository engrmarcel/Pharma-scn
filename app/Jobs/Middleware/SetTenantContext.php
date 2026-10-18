<?php

namespace App\Jobs\Middleware;

use App\Domain\Organizations\Models\Organization;
use App\Support\Tenancy\TenantContext;
use Closure;

/**
 * Restores the tenant a job was dispatched for. Jobs opt in through the
 * TenantAware trait, which captures the organization id at dispatch time.
 */
class SetTenantContext
{
    public function __construct(private readonly string $organizationId) {}

    public function handle(object $job, Closure $next): mixed
    {
        $organization = Organization::query()->findOrFail($this->organizationId);

        return app(TenantContext::class)->runAs($organization, fn () => $next($job));
    }
}

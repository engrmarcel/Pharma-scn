<?php

namespace App\Jobs\Middleware;

use App\Support\Tenancy\TenantContext;
use LogicException;

/**
 * Use in queued jobs that touch tenant-owned data. Call captureTenant() in the
 * constructor; the job then runs inside that organization's context.
 */
trait TenantAware
{
    public ?string $tenantOrganizationId = null;

    protected function captureTenant(): void
    {
        $this->tenantOrganizationId = app(TenantContext::class)->id()
            ?? throw new LogicException(static::class.' must be dispatched inside a tenant context.');
    }

    public function middleware(): array
    {
        return [new SetTenantContext($this->tenantOrganizationId)];
    }
}

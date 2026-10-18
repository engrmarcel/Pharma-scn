<?php

namespace App\Support\Tenancy;

use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMembership;

/**
 * The organization the current request or job acts for. Registered as a
 * scoped singleton, so it resets between requests and queued jobs.
 *
 * It is only ever populated by server-side code that has already checked
 * membership (ResolveTenant middleware, SetTenantContext job middleware);
 * a client-supplied organization id never reaches it unchecked.
 */
class TenantContext
{
    private ?Organization $organization = null;

    private ?OrganizationMembership $membership = null;

    public function set(Organization $organization, ?OrganizationMembership $membership = null): void
    {
        $this->organization = $organization;
        $this->membership = $membership;
    }

    public function clear(): void
    {
        $this->organization = null;
        $this->membership = null;
    }

    public function organization(): ?Organization
    {
        return $this->organization;
    }

    public function id(): ?string
    {
        return $this->organization?->getKey();
    }

    public function membership(): ?OrganizationMembership
    {
        return $this->membership;
    }

    public function has(): bool
    {
        return $this->organization !== null;
    }

    /**
     * Run a callback as the given organization, restoring the previous context afterwards.
     */
    public function runAs(Organization $organization, callable $callback, ?OrganizationMembership $membership = null): mixed
    {
        [$previousOrg, $previousMembership] = [$this->organization, $this->membership];
        $this->set($organization, $membership);

        try {
            return $callback();
        } finally {
            $this->organization = $previousOrg;
            $this->membership = $previousMembership;
        }
    }
}

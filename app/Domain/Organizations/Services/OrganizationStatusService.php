<?php

namespace App\Domain\Organizations\Services;

use App\Domain\Organizations\Models\Organization;
use App\Domain\Platform\Services\AuditLogger;
use App\Models\User;
use App\Support\Exceptions\BusinessRuleViolation;

/**
 * Suspension blocks the organization's members from acting in it. Records
 * are retained untouched; nothing is deleted.
 */
class OrganizationStatusService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function suspend(Organization $organization, User $actor, string $reason): Organization
    {
        if (! $organization->isActive()) {
            throw new BusinessRuleViolation('organization_already_suspended', 'Organization is already suspended.', 409);
        }

        $organization->forceFill(['status' => 'suspended', 'suspended_at' => now()])->save();
        $this->audit->record('organization.suspended', $organization, ['status' => 'active'], ['status' => 'suspended'], ['reason' => $reason], $organization->id);

        return $organization;
    }

    public function reactivate(Organization $organization, User $actor, ?string $reason = null): Organization
    {
        if ($organization->isActive()) {
            throw new BusinessRuleViolation('organization_not_suspended', 'Organization is not suspended.', 409);
        }

        $organization->forceFill(['status' => 'active', 'suspended_at' => null])->save();
        $this->audit->record('organization.reactivated', $organization, ['status' => 'suspended'], ['status' => 'active'], ['reason' => $reason], $organization->id);

        return $organization;
    }
}

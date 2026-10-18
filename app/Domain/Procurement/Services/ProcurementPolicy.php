<?php

namespace App\Domain\Procurement\Services;

use App\Models\User;
use App\Support\Exceptions\BusinessRuleViolation;
use App\Support\Tenancy\TenantContext;

/**
 * Organization procurement settings (organizations.settings.procurement):
 *   approval_threshold             - POs with total >= threshold need approval; NULL = all POs need approval
 *   allow_self_approval            - false: the approver must differ from the creator (segregation of duties)
 *   over_receipt_tolerance_percent - how far a receipt may exceed the ordered quantity (default 0)
 */
class ProcurementPolicy
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function settings(): array
    {
        $s = $this->tenant->organization()?->settings ?? [];

        return [
            'approval_threshold' => data_get($s, 'procurement.approval_threshold'),
            'allow_self_approval' => (bool) data_get($s, 'procurement.allow_self_approval', false),
            'over_receipt_tolerance_percent' => (string) data_get($s, 'procurement.over_receipt_tolerance_percent', '0'),
        ];
    }

    public function needsApproval(string $total): bool
    {
        $threshold = $this->settings()['approval_threshold'];

        return $threshold === null || bccomp($total, (string) $threshold, 4) >= 0;
    }

    public function assertMayApprove(?int $creatorId, User $approver): void
    {
        if ($creatorId === $approver->id && ! $this->settings()['allow_self_approval']) {
            throw new BusinessRuleViolation('self_approval_not_allowed', 'Approval must come from someone other than the person who raised the document.', 403);
        }
    }

    public function maxReceivable(string $ordered): string
    {
        $tolerance = $this->settings()['over_receipt_tolerance_percent'];

        return bcadd($ordered, bcdiv(bcmul($ordered, $tolerance, 8), '100', 8), 4);
    }
}

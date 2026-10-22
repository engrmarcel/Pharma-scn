<?php

namespace App\Domain\Crm\Services;

use App\Domain\Crm\Models\Opportunity;
use App\Domain\Crm\Models\VisitPlan;
use App\Domain\Crm\Models\VisitPlanItem;
use App\Domain\FieldForce\Models\FieldFollowUp;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Parties\Models\Customer;
use App\Domain\Platform\Services\AuditLogger;
use App\Support\Exceptions\BusinessRuleViolation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Territory management: hands a representative's accounts to a colleague
 * (leaver, leave cover, re-territorialisation). Moves the assigned customers
 * (optionally only those of one territory), the open follow-ups and open
 * opportunities for those customers, and cancels the leaver's future planned
 * calls on them. Completed history keeps its original owner.
 */
class HandoverService
{
    public function __construct(private readonly TenantContext $tenant, private readonly AuditLogger $audit) {}

    public function handover(int $fromUserId, int $toUserId, ?string $territoryId): array
    {
        if ($fromUserId === $toUserId) {
            throw new BusinessRuleViolation('handover_same_user', 'Choose a different representative to hand over to.', 422);
        }
        if (! OrganizationMembership::query()->where('organization_id', $this->tenant->id())->where('user_id', $toUserId)->where('status', 'active')->exists()) {
            throw new BusinessRuleViolation('representative_invalid', 'The receiving representative must be an active member of the organization.', 422);
        }

        return DB::transaction(function () use ($fromUserId, $toUserId, $territoryId) {
            $customers = Customer::query()->where('assigned_representative_id', $fromUserId)
                ->when($territoryId, fn ($q, $t) => $q->where('territory_id', $t))->lockForUpdate()->pluck('id');

            $counts = [
                'customers' => Customer::query()->whereIn('id', $customers)->update(['assigned_representative_id' => $toUserId]),
                'follow_ups' => FieldFollowUp::query()->where('assigned_to', $fromUserId)->where('status', 'open')
                    ->when($territoryId, fn ($q) => $q->whereIn('customer_id', $customers))->update(['assigned_to' => $toUserId]),
                'opportunities' => Opportunity::query()->where('owner_id', $fromUserId)->whereIn('stage', Opportunity::OPEN_STAGES)
                    ->when($territoryId, fn ($q, $t) => $q->where(fn ($w) => $w->whereIn('customer_id', $customers)->orWhere(fn ($l) => $l->whereNull('customer_id')->where('territory_id', $t))))
                    ->update(['owner_id' => $toUserId, 'last_activity_at' => now()]),
                'planned_visits_cancelled' => VisitPlanItem::query()->where('status', 'planned')->where('planned_date', '>=', today()->toDateString())
                    ->whereIn('customer_id', $customers)
                    ->whereIn('visit_plan_id', VisitPlan::query()->where('representative_id', $fromUserId)->select('id'))
                    ->update(['status' => 'cancelled', 'cancel_reason' => 'Handed over to another representative']),
            ];
            $this->audit->record('field_force.handover', null, new: ['from_user_id' => $fromUserId, 'to_user_id' => $toUserId, 'territory_id' => $territoryId] + $counts);

            return $counts;
        });
    }
}

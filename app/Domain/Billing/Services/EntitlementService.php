<?php

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Models\OrganizationSubscription;
use App\Domain\Billing\Models\SubscriptionPlan;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Platform\Services\AuditLogger;
use App\Models\User;
use App\Support\Exceptions\BusinessRuleViolation;
use Illuminate\Support\Facades\DB;

/**
 * Backend enforcement of plan entitlements (spec §21.2). UI hiding is a
 * convenience only; every limited resource is created through guard().
 *
 * Expired or suspended subscriptions block *new* consumption only. Existing
 * records stay readable and nothing is deleted (spec §21.4).
 */
class EntitlementService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function currentSubscription(Organization $organization): ?OrganizationSubscription
    {
        return OrganizationSubscription::query()->with('plan')
            ->where('organization_id', $organization->id)
            ->whereNull('ended_at')
            ->latest('starts_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Effective entitlements: plan values overlaid by organization overrides.
     *
     * @return array<string, array{enabled: bool, limit: int|null}>
     */
    public function entitlements(Organization $organization): array
    {
        $plan = $this->currentSubscription($organization)?->plan?->entitlementMap() ?? [];
        $overrides = DB::table('organization_entitlement_overrides')->where('organization_id', $organization->id)->get()
            ->mapWithKeys(fn ($row) => [$row->key => ['enabled' => (bool) $row->enabled, 'limit' => $row->limit_value === null ? null : (int) $row->limit_value]])
            ->all();

        $effective = [];
        foreach (array_keys(config('entitlements.keys')) as $key) {
            // Keys missing from the plan are not granted.
            $effective[$key] = $overrides[$key] ?? $plan[$key] ?? ['enabled' => false, 'limit' => 0];
        }

        return $effective;
    }

    public function allows(Organization $organization, string $feature): bool
    {
        $subscription = $this->currentSubscription($organization);

        return $subscription !== null && $subscription->isOperational()
            && ($this->entitlements($organization)[$feature]['enabled'] ?? false);
    }

    /** @return array<string, int> */
    public function usage(Organization $organization): array
    {
        return [
            'users' => DB::table('organization_memberships')->where('organization_id', $organization->id)->where('status', 'active')->count()
                + DB::table('organization_invitations')->where('organization_id', $organization->id)
                    ->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->count(),
            'branches' => DB::table('branches')->where('organization_id', $organization->id)->count(),
            'warehouses' => DB::table('warehouses')->where('organization_id', $organization->id)->count(),
            'pos_terminals' => DB::table('pos_terminals')->where('organization_id', $organization->id)->where('status', 'active')->count(),
            'storage_mb' => (int) ceil(DB::table('attachments')->where('organization_id', $organization->id)->sum('size_bytes') / 1048576),
        ];
    }

    /**
     * Check a limited resource and run the creation callback while holding a
     * lock on the organization row, so concurrent requests cannot both take
     * the last slot.
     */
    public function guard(Organization $organization, string $key, callable $create, int $adding = 1): mixed
    {
        return DB::transaction(function () use ($organization, $key, $create, $adding) {
            Organization::query()->whereKey($organization->id)->lockForUpdate()->first();

            $subscription = $this->currentSubscription($organization);
            if ($subscription === null || ! $subscription->isOperational()) {
                throw new BusinessRuleViolation('subscription_inactive', 'The organization has no active subscription. Existing records remain available; adding new ones requires an active plan.', 403, [
                    'subscription_status' => $subscription?->status,
                ]);
            }

            $entitlement = $this->entitlements($organization)[$key] ?? ['enabled' => false, 'limit' => 0];
            $used = $this->usage($organization)[$key] ?? 0;

            if (! $entitlement['enabled'] || ($entitlement['limit'] !== null && $used + $adding > $entitlement['limit'])) {
                throw new BusinessRuleViolation('entitlement_limit_reached', "The plan allows {$entitlement['limit']} {$key}; {$used} in use.", 403, [
                    'entitlement' => $key, 'limit' => $entitlement['limit'], 'used' => $used,
                ]);
            }

            return $create();
        });
    }

    public function subscribe(Organization $organization, SubscriptionPlan $plan, ?User $actor, ?string $status = null): OrganizationSubscription
    {
        return DB::transaction(function () use ($organization, $plan, $actor, $status) {
            $previous = $this->currentSubscription($organization);
            $previous?->forceFill(['ended_at' => now()])->save();

            $status ??= $plan->trial_days > 0 ? 'trialing' : 'active';
            $subscription = OrganizationSubscription::query()->create([
                'organization_id' => $organization->id,
                'plan_id' => $plan->id,
                'status' => $status,
                'starts_at' => now(),
                'trial_ends_at' => $status === 'trialing' ? now()->addDays($plan->trial_days) : null,
                'changed_by' => $actor?->id,
            ]);

            $this->audit->record('subscription.changed', $organization,
                ['plan' => $previous?->plan?->code, 'status' => $previous?->status],
                ['plan' => $plan->code, 'status' => $status],
                organizationId: $organization->id);

            return $subscription;
        });
    }

    public function setStatus(Organization $organization, string $status, User $actor): OrganizationSubscription
    {
        $subscription = $this->currentSubscription($organization)
            ?? throw new BusinessRuleViolation('subscription_missing', 'Organization has no subscription.', 404);

        $before = $subscription->status;
        $subscription->forceFill([
            'status' => $status,
            'cancelled_at' => $status === 'cancelled' ? now() : $subscription->cancelled_at,
            'changed_by' => $actor->id,
        ])->save();

        $this->audit->record('subscription.status_changed', $organization, ['status' => $before], ['status' => $status], organizationId: $organization->id);

        return $subscription;
    }

    public function setOverride(Organization $organization, string $key, bool $enabled, ?int $limit, string $reason, User $actor): void
    {
        DB::table('organization_entitlement_overrides')->updateOrInsert(
            ['organization_id' => $organization->id, 'key' => $key],
            ['enabled' => $enabled, 'limit_value' => $limit, 'reason' => $reason, 'granted_by' => $actor->id, 'updated_at' => now(), 'created_at' => now()],
        );

        $this->audit->record('subscription.entitlement_override', $organization, new: compact('key', 'enabled', 'limit', 'reason'), organizationId: $organization->id);
    }
}

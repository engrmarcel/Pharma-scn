<?php

namespace App\Domain\Organizations\Services;

use App\Domain\Access\Models\Role;
use App\Domain\Billing\Services\EntitlementService;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationInvitation;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Platform\Services\AuditLogger;
use App\Models\User;
use App\Notifications\OrganizationInvitationNotification;
use App\Support\Exceptions\BusinessRuleViolation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class MembershipService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenant,
        private readonly EntitlementService $entitlements,
    ) {}

    /**
     * @return array{0: OrganizationInvitation, 1: string} the invitation and its one-time plain token
     */
    public function invite(Organization $organization, string $email, array $roleCodes, User $inviter): array
    {
        $email = Str::lower(trim($email));
        $this->resolveRoles($organization, $roleCodes);

        $existing = OrganizationMembership::query()
            ->where('organization_id', $organization->id)
            ->whereHas('user', fn ($q) => $q->where('email', $email))
            ->exists();
        if ($existing) {
            throw new BusinessRuleViolation('already_member', 'This user is already a member of the organization.', 409);
        }

        $token = Str::random(48);
        // A pending invitation reserves a user seat; accepting it converts the seat.
        $invitation = $this->entitlements->guard($organization, 'users', fn () => $this->tenant->runAs($organization, fn () => OrganizationInvitation::query()->create([
            'email' => $email,
            'role_codes' => array_values(array_unique($roleCodes)),
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(7),
            'invited_by' => $inviter->id,
        ])));

        Notification::route('mail', $email)->notify(new OrganizationInvitationNotification($organization, $token));
        $this->audit->record('membership.invited', $invitation, new: ['email' => $email, 'roles' => $roleCodes], organizationId: $organization->id);

        return [$invitation, $token];
    }

    public function accept(string $token, User $user): OrganizationMembership
    {
        return DB::transaction(function () use ($token, $user) {
            $invitation = OrganizationInvitation::acrossTenants()
                ->where('token_hash', hash('sha256', $token))
                ->lockForUpdate()
                ->first();

            if ($invitation === null || ! $invitation->isPending()) {
                throw new BusinessRuleViolation('invitation_invalid', 'This invitation is invalid or has expired.', 404);
            }
            if (Str::lower($user->email) !== $invitation->email) {
                throw new BusinessRuleViolation('invitation_email_mismatch', 'This invitation was issued to a different email address.', 403);
            }

            $organization = Organization::query()->findOrFail($invitation->organization_id);
            $roles = $this->resolveRoles($organization, $invitation->role_codes);

            $membership = OrganizationMembership::query()->firstOrCreate(
                ['organization_id' => $organization->id, 'user_id' => $user->id],
                ['status' => 'active', 'all_locations' => true, 'invited_by' => $invitation->invited_by, 'joined_at' => now()],
            );
            $membership->roles()->syncWithoutDetaching($roles->pluck('id'));

            $invitation->forceFill(['accepted_at' => now()])->save();
            $this->audit->record('membership.joined', $membership, new: ['user_id' => $user->id, 'roles' => $invitation->role_codes], organizationId: $organization->id);

            return $membership;
        });
    }

    public function updateRoles(OrganizationMembership $membership, array $roleCodes, User $actor): OrganizationMembership
    {
        return DB::transaction(function () use ($membership, $roleCodes, $actor) {
            $organization = $membership->organization;
            $roles = $this->resolveRoles($organization, $roleCodes);
            $before = $membership->roles()->pluck('code')->all();

            if (in_array('org_owner', $before, true) && ! in_array('org_owner', $roleCodes, true)) {
                $this->assertNotLastOwner($membership);
            }
            if ($membership->user_id === $actor->id && in_array('org_owner', $before, true) && ! in_array('org_owner', $roleCodes, true)) {
                throw new BusinessRuleViolation('cannot_demote_self', 'Owners cannot remove their own owner role.', 403);
            }

            $membership->roles()->sync($roles->pluck('id'));
            $this->audit->record('membership.roles_changed', $membership, ['roles' => $before], ['roles' => $roleCodes], organizationId: $organization->id);

            return $membership->load('roles');
        });
    }

    public function setStatus(OrganizationMembership $membership, string $status, User $actor): OrganizationMembership
    {
        if ($membership->user_id === $actor->id) {
            throw new BusinessRuleViolation('cannot_change_own_membership', 'You cannot change your own membership status.', 403);
        }
        if ($status !== 'active' && $membership->roles()->where('code', 'org_owner')->exists()) {
            $this->assertNotLastOwner($membership);
        }

        $before = $membership->status;
        $membership->forceFill(['status' => $status])->save();
        $this->audit->record('membership.status_changed', $membership, ['status' => $before], ['status' => $status], organizationId: $membership->organization_id);

        return $membership;
    }

    /** @return Collection<int, Role> */
    private function resolveRoles(Organization $organization, array $codes): Collection
    {
        $codes = array_values(array_unique($codes));
        $roles = Role::query()->assignableBy($organization->id)->whereIn('code', $codes)->get()
            // Prefer an organization's custom role over a system role with the same code.
            ->sortBy(fn (Role $r) => $r->organization_id === null ? 1 : 0)
            ->unique('code');

        $unknown = array_diff($codes, $roles->pluck('code')->all());
        if ($codes === [] || $unknown !== []) {
            throw new BusinessRuleViolation('roles_invalid', 'One or more roles are not assignable in this organization.', 422, ['unknown_roles' => array_values($unknown)]);
        }

        return $roles->values();
    }

    private function assertNotLastOwner(OrganizationMembership $membership): void
    {
        $otherOwners = OrganizationMembership::query()
            ->where('organization_id', $membership->organization_id)
            ->where('id', '!=', $membership->id)
            ->where('status', 'active')
            ->whereHas('roles', fn ($q) => $q->where('code', 'org_owner'))
            ->exists();

        if (! $otherOwners) {
            throw new BusinessRuleViolation('last_owner', 'An organization must keep at least one active owner.', 409);
        }
    }
}

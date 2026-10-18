<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Access\Models\Role;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Organizations\Services\MembershipService;
use App\Domain\Platform\Services\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Resources\MembershipResource;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class MemberController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly MembershipService $memberships,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('organization.view');

        return MembershipResource::collection(
            $this->membershipQuery()->with(['user', 'roles'])->orderBy('created_at')->paginate(50)
        );
    }

    public function roles(): JsonResponse
    {
        Gate::authorize('organization.view');

        return response()->json(['data' => Role::query()->assignableBy($this->tenant->id())->orderBy('name')
            ->get(['id', 'code', 'name', 'description', 'is_system', 'organization_id'])]);
    }

    public function invite(Request $request): JsonResponse
    {
        Gate::authorize('organization.members.manage');

        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string'],
        ]);

        // Only owners can create other owners.
        if (in_array('org_owner', $data['roles'], true)) {
            $this->assertCurrentUserIsOwner();
        }

        [$invitation] = $this->memberships->invite($this->tenant->organization(), $data['email'], $data['roles'], $request->user());

        return response()->json(['data' => [
            'id' => $invitation->id,
            'email' => $invitation->email,
            'roles' => $invitation->role_codes,
            'expires_at' => $invitation->expires_at->toIso8601String(),
        ]], 201);
    }

    public function updateRoles(Request $request, string $membershipId): MembershipResource
    {
        Gate::authorize('organization.members.manage');

        $data = $request->validate(['roles' => ['required', 'array', 'min:1'], 'roles.*' => ['string']]);
        $membership = $this->membershipQuery()->findOrFail($membershipId);

        $touchesOwner = in_array('org_owner', $data['roles'], true) || $membership->roles()->where('code', 'org_owner')->exists();
        if ($touchesOwner) {
            $this->assertCurrentUserIsOwner();
        }

        return MembershipResource::make($this->memberships->updateRoles($membership, $data['roles'], $request->user())->load('user'));
    }

    public function updateStatus(Request $request, string $membershipId): MembershipResource
    {
        Gate::authorize('organization.members.manage');

        $data = $request->validate(['status' => ['required', Rule::in(['active', 'suspended', 'revoked'])]]);
        $membership = $this->membershipQuery()->findOrFail($membershipId);

        if ($membership->roles()->where('code', 'org_owner')->exists()) {
            $this->assertCurrentUserIsOwner();
        }

        return MembershipResource::make($this->memberships->setStatus($membership, $data['status'], $request->user())->load(['user', 'roles']));
    }

    /** Restrict a member to specific branches/warehouses, or give access to all locations. */
    public function updateLocations(Request $request, string $membershipId, AuditLogger $audit): MembershipResource
    {
        Gate::authorize('organization.members.manage');
        $membership = $this->membershipQuery()->findOrFail($membershipId);
        $orgId = $this->tenant->id();

        $data = $request->validate([
            'all_locations' => ['required', 'boolean'],
            'branch_ids' => ['array'],
            'branch_ids.*' => ['ulid', Rule::exists('branches', 'id')->where('organization_id', $orgId)],
            'warehouse_ids' => ['array'],
            'warehouse_ids.*' => ['ulid', Rule::exists('warehouses', 'id')->where('organization_id', $orgId)],
        ]);

        DB::transaction(function () use ($membership, $data) {
            $membership->forceFill(['all_locations' => $data['all_locations']])->save();
            $membership->locationScopes()->delete();
            if (! $data['all_locations']) {
                foreach (['branch' => $data['branch_ids'] ?? [], 'warehouse' => $data['warehouse_ids'] ?? []] as $type => $ids) {
                    foreach (array_unique($ids) as $id) {
                        $membership->locationScopes()->create(['location_type' => $type, 'location_id' => $id]);
                    }
                }
            }
        });
        $audit->record('membership.locations_changed', $membership, new: $data);

        return MembershipResource::make($membership->fresh()->load(['user', 'roles']));
    }

    /** Accepting needs no tenant context: the invitation token identifies the organization. */
    public function acceptInvitation(Request $request): MembershipResource
    {
        $data = $request->validate(['token' => ['required', 'string', 'size:48']]);

        return MembershipResource::make($this->memberships->accept($data['token'], $request->user())->load(['organization', 'roles']));
    }

    private function membershipQuery()
    {
        return OrganizationMembership::query()->where('organization_id', $this->tenant->id());
    }

    private function assertCurrentUserIsOwner(): void
    {
        abort_unless($this->tenant->membership()?->roles()->where('code', 'org_owner')->exists(), 403, 'Only organization owners can grant or change the owner role.');
    }
}

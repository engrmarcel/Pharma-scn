<?php

namespace App\Livewire\Organization;

use App\Domain\Organizations\Models\OrganizationMembership;
use App\Livewire\WebPage;
use App\Support\Tenancy\TenantContext;
use Livewire\Attributes\Title;

/** Members: invitations, roles, status and the locations each member may work in. */
#[Title('Members & roles')]
class Members extends WebPage
{
    public const ROUTE = 'organization.members';

    public ?array $invite = null;

    /** Membership being edited: id, name, roles[], status, all_locations, branch_ids[], warehouse_ids[]. */
    public ?array $member = null;

    public ?array $invited = null;

    public function startInvite(): void
    {
        $this->resetErrorBag();
        $this->invite = ['email' => '', 'roles' => []];
    }

    public function sendInvite(): void
    {
        $result = $this->submit('invite', 'invitations', $this->invite, 'Invitation created.');
        if ($result !== null) {
            $this->invited = $result['data'];
            $this->invite = null;
        }
    }

    public function edit(array $row): void
    {
        $this->resetErrorBag();
        $this->member = [
            'id' => $row['id'], 'name' => $row['user']['name'] ?? '', 'status' => $row['status'],
            'roles' => collect($row['roles'] ?? [])->pluck('code')->all(),
            'all_locations' => (bool) $row['all_locations'], 'branch_ids' => [], 'warehouse_ids' => [],
        ];
        $scopes = OrganizationMembership::query()->where('organization_id', app(TenantContext::class)->id())
            ->find($row['id'])?->locationScopes()->get() ?? collect();
        $this->member['branch_ids'] = $scopes->where('location_type', 'branch')->pluck('location_id')->values()->all();
        $this->member['warehouse_ids'] = $scopes->where('location_type', 'warehouse')->pluck('location_id')->values()->all();
    }

    public function saveRoles(): void
    {
        $this->submit('member', "members/{$this->member['id']}/roles", ['roles' => $this->member['roles']], 'Roles updated.', 'put');
    }

    public function saveLocations(): void
    {
        $this->submit('member', "members/{$this->member['id']}/locations", [
            'all_locations' => (bool) $this->member['all_locations'],
            'branch_ids' => array_values($this->member['branch_ids']),
            'warehouse_ids' => array_values($this->member['warehouse_ids']),
        ], 'Locations updated.', 'put');
    }

    public function setStatus(string $status): void
    {
        if ($this->submit('member', "members/{$this->member['id']}/status", ['status' => $status], 'Member is now '.$status.'.', 'put') !== null) {
            $this->member['status'] = $status;
        }
    }

    public function render()
    {
        $members = $this->api()->get('members', ['page' => $this->page]);

        return view('livewire.organization.members', [
            'members' => $members['data'] ?? [],
            'meta' => $members['meta'] ?? [],
            'roles' => $this->read('roles')['data'] ?? [],
            'branches' => $this->read('branches')['data'] ?? [],
            'warehouses' => $this->read('warehouses')['data'] ?? [],
            'canManage' => $this->can('organization.members.manage'),
        ]);
    }
}

<?php

namespace App\Livewire\FieldForce;

use App\Livewire\WebPage;
use Livewire\Attributes\Title;

/** Territories: who works them, which warehouse supplies them, and which customers belong to them. */
#[Title('Territories')]
class Territories extends WebPage
{
    public const ROUTE = 'field-force.territories';

    public ?array $territory = null;

    /** Members being edited: territory id and rows of [user_id, role]. */
    public ?array $members = null;

    /** Customer assignment: territory id, customer ids, representative. */
    public ?array $assign = null;

    public string $customerSearch = '';

    public function create(): void
    {
        $this->resetErrorBag();
        $this->territory = ['id' => null, 'code' => '', 'name' => '', 'parent_id' => '', 'warehouse_id' => '', 'description' => '', 'status' => 'active'];
    }

    public function edit(array $t): void
    {
        $this->resetErrorBag();
        $this->territory = ['id' => $t['id']] + array_map(fn ($v) => $v ?? '', array_intersect_key($t, array_flip(['code', 'name', 'parent_id', 'warehouse_id', 'description', 'status'])));
    }

    public function save(): void
    {
        $t = $this->territory;
        $data = collect($t)->except('id')->map(fn ($v) => $v === '' ? null : $v)->all();
        $result = $t['id'] ? $this->submit('territory', "field-force/territories/{$t['id']}", $data, 'Territory updated.', 'patch')
            : $this->submit('territory', 'field-force/territories', $data, 'Territory created.');
        if ($result !== null) {
            $this->territory = null;
        }
    }

    public function editMembers(array $t): void
    {
        $this->resetErrorBag();
        $this->members = ['id' => $t['id'], 'name' => $t['name'], 'rows' => collect($t['members'])->map(fn ($m) => ['user_id' => (string) $m['user_id'], 'role' => $m['role']])->all()];
    }

    public function addMember(): void
    {
        $this->members['rows'][] = ['user_id' => '', 'role' => 'representative'];
    }

    public function removeMember(int $i): void
    {
        unset($this->members['rows'][$i]);
        $this->members['rows'] = array_values($this->members['rows']);
    }

    public function saveMembers(): void
    {
        $rows = array_values(array_filter(array_map(fn ($r) => ['user_id' => (int) $r['user_id'], 'role' => $r['role']], $this->members['rows']), fn ($r) => $r['user_id'] > 0));
        if ($this->submit('members', "field-force/territories/{$this->members['id']}/members", ['members' => $rows], 'Members saved.', 'put') !== null) {
            $this->members = null;
        }
    }

    public function startAssign(array $t): void
    {
        $this->resetErrorBag();
        $this->customerSearch = '';
        $this->assign = ['territory_id' => $t['id'], 'name' => $t['name'], 'customer_ids' => [], 'representative' => ''];
    }

    public function saveAssign(): void
    {
        $body = ['customer_ids' => array_keys(array_filter($this->assign['customer_ids'])), 'territory_id' => $this->assign['territory_id']];
        if ($this->assign['representative'] !== '') {
            $body['assigned_representative_id'] = (int) $this->assign['representative'];
        }
        $result = $this->submit('assign', 'field-force/customer-assignments', $body);
        if ($result !== null) {
            $this->notify(($result['data']['customers_updated'] ?? 0).' customers assigned.');
            $this->assign = null;
        }
    }

    public function render()
    {
        $territories = $this->api()->get('field-force/territories')['data'] ?? [];
        $people = collect($this->read('members')['data'] ?? [])->where('status', 'active')->mapWithKeys(fn ($m) => [$m['user']['id'] => $m['user']['name']]);

        return view('livewire.field-force.territories', [
            'territories' => $territories,
            'warehouses' => $this->warehouseOptions(),
            'people' => $people,
            'customers' => $this->assign ? ($this->read('customers', ['q' => trim($this->customerSearch)])['data'] ?? []) : [],
            'canManage' => $this->can('field_force.territories.manage'),
        ]);
    }
}

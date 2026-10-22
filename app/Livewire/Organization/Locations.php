<?php

namespace App\Livewire\Organization;

use App\Livewire\WebPage;
use Livewire\Attributes\Title;

/** Branches, warehouses and the storage bins inside a warehouse. */
#[Title('Branches & warehouses')]
class Locations extends WebPage
{
    public const ROUTE = 'organization.locations';

    /** Branch being created or edited (id null = new). */
    public ?array $branch = null;

    public ?array $warehouse = null;

    /** Warehouse whose bins are open. */
    public ?string $binsFor = null;

    public array $bin = ['code' => '', 'name' => ''];

    public function editBranch(?array $row = null): void
    {
        $this->resetErrorBag();
        $this->branch = $row ? array_intersect_key($row, array_flip(['id', 'code', 'name', 'type', 'address_line1', 'city', 'state_region', 'phone', 'status']))
            : ['id' => null, 'code' => '', 'name' => '', 'type' => 'branch', 'address_line1' => '', 'city' => '', 'state_region' => '', 'phone' => '', 'status' => 'active'];
    }

    public function saveBranch(): void
    {
        $data = collect($this->branch)->except('id')->map(fn ($v) => $v === '' ? null : $v)->all();
        $result = $this->branch['id']
            ? $this->submit('branch', "branches/{$this->branch['id']}", collect($data)->except('code')->all(), 'Branch updated.', 'patch')
            : $this->submit('branch', 'branches', $data, 'Branch created.');
        if ($result !== null) {
            $this->branch = null;
        }
    }

    public function editWarehouse(?array $row = null): void
    {
        $this->resetErrorBag();
        $this->warehouse = $row ? array_intersect_key($row, array_flip(['id', 'code', 'name', 'type', 'branch_id', 'address_line1', 'city', 'status', 'gln']))
            : ['id' => null, 'code' => '', 'name' => '', 'type' => 'general', 'branch_id' => '', 'address_line1' => '', 'city' => '', 'status' => 'active', 'gln' => ''];
    }

    public function saveWarehouse(): void
    {
        $data = collect($this->warehouse)->except('id')->map(fn ($v) => $v === '' ? null : $v)->all();
        $result = $this->warehouse['id']
            ? $this->submit('warehouse', "warehouses/{$this->warehouse['id']}", collect($data)->except('code')->all(), 'Warehouse updated.', 'patch')
            : $this->submit('warehouse', 'warehouses', $data, 'Warehouse created.');
        if ($result !== null) {
            $this->warehouse = null;
        }
    }

    public function showBins(string $warehouseId): void
    {
        $this->binsFor = $warehouseId;
        $this->bin = ['code' => '', 'name' => ''];
    }

    public function addBin(): void
    {
        if ($this->submit('bin', "warehouses/{$this->binsFor}/bins", array_filter($this->bin), 'Bin added.') !== null) {
            $this->bin = ['code' => '', 'name' => ''];
        }
    }

    public function render()
    {
        $branches = $this->read('branches')['data'] ?? [];

        return view('livewire.organization.locations', [
            'branches' => $branches,
            'branchNames' => collect($branches)->pluck('name', 'id'),
            'warehouses' => $this->read('warehouses')['data'] ?? [],
            'bins' => $this->binsFor ? ($this->read("warehouses/{$this->binsFor}/bins", default: ['data' => null])['data']) : [],
            'canManage' => $this->can('locations.manage'),
            'canBins' => $this->can('inventory.settings.manage'),
        ]);
    }
}

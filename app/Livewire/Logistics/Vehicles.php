<?php

namespace App\Livewire\Logistics;

use App\Livewire\WebPage;
use Livewire\Attributes\Title;

/** The organization's vehicles and whether each can carry cold-chain goods. */
#[Title('Vehicles')]
class Vehicles extends WebPage
{
    public const ROUTE = 'logistics.vehicles';

    public ?array $vehicle = null;

    public function create(): void
    {
        $this->resetErrorBag();
        $this->vehicle = ['id' => null, 'registration' => '', 'description' => '', 'refrigerated' => false, 'status' => 'active'];
    }

    public function edit(array $v): void
    {
        $this->resetErrorBag();
        $this->vehicle = ['id' => $v['id'], 'registration' => $v['registration'], 'description' => $v['description'] ?? '', 'refrigerated' => (bool) $v['refrigerated'], 'status' => $v['status']];
    }

    public function save(): void
    {
        $v = $this->vehicle;
        $result = $v['id']
            ? $this->submit('vehicle', "logistics/vehicles/{$v['id']}", ['description' => $v['description'] ?: null, 'refrigerated' => (bool) $v['refrigerated'], 'status' => $v['status']], 'Vehicle updated.', 'patch')
            : $this->submit('vehicle', 'logistics/vehicles', ['registration' => $v['registration'], 'description' => $v['description'] ?: null, 'refrigerated' => (bool) $v['refrigerated']], 'Vehicle added.');
        if ($result !== null) {
            $this->vehicle = null;
        }
    }

    public function render()
    {
        return view('livewire.logistics.vehicles', ['vehicles' => $this->api()->get('logistics/vehicles')['data'] ?? [], 'canManage' => $this->can('logistics.manage')]);
    }
}

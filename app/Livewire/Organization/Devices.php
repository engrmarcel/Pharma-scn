<?php

namespace App\Livewire\Organization;

use App\Livewire\WebPage;
use Livewire\Attributes\Title;

/** Registered phones, tablets and tills. Revoking stops the device at its next request; wiping also erases its local data. */
#[Title('Devices')]
class Devices extends WebPage
{
    public const ROUTE = 'organization.devices';

    public string $status = '';

    public ?array $revoking = null;

    protected array $filters = ['status'];

    public function startRevoke(string $id, string $name): void
    {
        $this->resetErrorBag();
        $this->revoking = ['id' => $id, 'name' => $name, 'reason' => '', 'wipe' => false];
    }

    public function revoke(): void
    {
        if ($this->submit('revoking', "devices/{$this->revoking['id']}/revoke", ['reason' => $this->revoking['reason'], 'wipe' => (bool) $this->revoking['wipe']], 'Device revoked.') !== null) {
            $this->revoking = null;
        }
    }

    public function render()
    {
        $result = $this->api()->get('devices', ['status' => $this->status, 'page' => $this->page]);

        return view('livewire.organization.devices', ['rows' => $result['data'] ?? [], 'meta' => $result]);
    }
}

<?php

namespace App\Livewire\Pos;

use App\Livewire\WebPage;
use Livewire\Attributes\Title;

/** POS terminals: each register sells from one warehouse; disable a lost or retired till. */
#[Title('POS terminals')]
class Terminals extends WebPage
{
    public const ROUTE = 'pos.terminals';

    public ?array $terminal = null;

    public function create(): void
    {
        $this->resetErrorBag();
        $this->terminal = ['warehouse_id' => '', 'code' => '', 'name' => '', 'device_identifier' => ''];
    }

    public function save(): void
    {
        if ($this->submit('terminal', 'pos/terminals', array_filter($this->terminal), 'Terminal registered.') !== null) {
            $this->terminal = null;
        }
    }

    public function setStatus(string $id, string $status): void
    {
        $this->attempt(fn ($api) => $api->patch("pos/terminals/{$id}", ['status' => $status]), $status === 'active' ? 'Terminal enabled.' : 'Terminal disabled.');
    }

    public function render()
    {
        return view('livewire.pos.terminals', [
            'terminals' => $this->api()->get('pos/terminals')['data'] ?? [],
            'warehouses' => $this->warehouseOptions(),
        ]);
    }
}

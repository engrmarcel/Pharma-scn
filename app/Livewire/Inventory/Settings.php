<?php

namespace App\Livewire\Inventory;

use App\Livewire\WebPage;
use Livewire\Attributes\Title;

/** Inventory policy: negative stock and minimum remaining shelf life when selling. */
#[Title('Inventory settings')]
class Settings extends WebPage
{
    public const ROUTE = 'inventory.settings';

    public array $form = [];

    public function mount(): void
    {
        $this->form = $this->api()->get('inventory/settings')['data'];
    }

    public function save(): void
    {
        $result = $this->submit('form', 'inventory/settings', ['allow_negative_stock' => (bool) $this->form['allow_negative_stock'], 'min_shelf_life_days' => (int) $this->form['min_shelf_life_days']], 'Inventory settings saved.', 'patch');
        if ($result !== null) {
            $this->form = $result['data'];
        }
    }

    public function render()
    {
        return view('livewire.inventory.settings');
    }
}

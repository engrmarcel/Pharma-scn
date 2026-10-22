<?php

namespace App\Livewire\Sales;

use App\Livewire\WebPage;
use Livewire\Attributes\Title;

/** Sales policy: the largest discount allowed without approval, and self-approval. */
#[Title('Sales settings')]
class Settings extends WebPage
{
    public const ROUTE = 'sales.settings';

    public array $form = [];

    public function mount(): void
    {
        $this->form = $this->api()->get('sales/settings')['data'];
    }

    public function save(): void
    {
        $this->submit('form', 'sales/settings', ['max_discount_percent' => (string) ($this->form['max_discount_percent'] ?: '0'), 'allow_self_approval' => (bool) $this->form['allow_self_approval']], 'Sales settings saved.', 'patch');
    }

    public function render()
    {
        return view('livewire.sales.settings');
    }
}

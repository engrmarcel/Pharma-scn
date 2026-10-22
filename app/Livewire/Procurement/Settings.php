<?php

namespace App\Livewire\Procurement;

use App\Livewire\WebPage;
use Livewire\Attributes\Title;

/** Procurement policy: approval threshold, self-approval and over-receipt tolerance. */
#[Title('Procurement settings')]
class Settings extends WebPage
{
    public const ROUTE = 'procurement.settings';

    public array $form = [];

    public function mount(): void
    {
        $s = $this->api()->get('procurement/settings')['data'];
        $this->form = ['approval_threshold' => $s['approval_threshold'] === null ? '' : (string) $s['approval_threshold'], 'allow_self_approval' => $s['allow_self_approval'],
            'over_receipt_tolerance_percent' => (string) $s['over_receipt_tolerance_percent']];
    }

    public function save(): void
    {
        $this->submit('form', 'procurement/settings', [
            'approval_threshold' => $this->form['approval_threshold'] === '' ? null : $this->form['approval_threshold'],
            'allow_self_approval' => (bool) $this->form['allow_self_approval'],
            'over_receipt_tolerance_percent' => $this->form['over_receipt_tolerance_percent'] ?: '0',
        ], 'Procurement settings saved.', 'patch');
    }

    public function render()
    {
        return view('livewire.procurement.settings', ['currency' => $this->currency()]);
    }
}

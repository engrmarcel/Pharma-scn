<?php

namespace App\Livewire\Logistics;

use App\Livewire\WebPage;
use Livewire\Attributes\Title;

/** Shipped against delivered and returned quantities for a period; failures, on-time rate and cold-chain exceptions. */
#[Title('Logistics reconciliation')]
class Reconciliation extends WebPage
{
    public const ROUTE = 'logistics.reconciliation';

    public string $from = '';

    public string $to = '';

    public function mount(): void
    {
        $this->from = business_today()->startOfMonth()->toDateString();
        $this->to = business_today()->toDateString();
    }

    public function render()
    {
        return view('livewire.logistics.reconciliation', ['data' => $this->read('logistics/reconciliation', ['from' => $this->from, 'to' => $this->to], ['data' => null])['data']]);
    }
}

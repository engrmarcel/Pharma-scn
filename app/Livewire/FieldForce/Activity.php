<?php

namespace App\Livewire\FieldForce;

use App\Livewire\WebPage;
use Livewire\Attributes\Title;

/** Activity per representative: visits, location verification, orders and follow-up compliance. */
#[Title('Field activity report')]
class Activity extends WebPage
{
    public const ROUTE = 'field-force.activity';

    public string $from = '';

    public string $to = '';

    public function mount(): void
    {
        $this->from = business_today()->startOfMonth()->toDateString();
        $this->to = business_today()->toDateString();
    }

    public function render()
    {
        return view('livewire.field-force.activity', [
            'report' => $this->read('field-force/reports/activity', ['from' => $this->from, 'to' => $this->to], ['data' => null])['data'],
            'currency' => $this->currency(),
        ]);
    }
}

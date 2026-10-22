<?php

namespace App\Livewire\Crm;

use App\Livewire\WebPage;
use Livewire\Attributes\Title;

/** CRM analytics: coverage, call frequency, visit quality, conversion, pipeline, detailing and feedback. */
#[Title('CRM analytics')]
class Analytics extends WebPage
{
    public const ROUTE = 'crm.analytics';

    public string $from = '';

    public string $to = '';

    public string $territory = '';

    public function mount(): void
    {
        $this->from = business_today()->subDays(29)->toDateString();
        $this->to = business_today()->toDateString();
    }

    public function render()
    {
        return view('livewire.crm.analytics', [
            'data' => $this->read('crm/analytics', ['from' => $this->from, 'to' => $this->to, 'territory_id' => $this->territory], ['data' => null])['data'],
            'territories' => collect($this->read('field-force/territories')['data'] ?? [])->pluck('name', 'id'),
            'currency' => $this->currency(),
        ]);
    }
}

<?php

namespace App\Livewire\Integration;

use App\Livewire\WebPage;
use Livewire\Attributes\Title;

/** Connector health: backlog, oldest pending message, quarantine and failures. */
#[Title('Integration health')]
class Health extends WebPage
{
    public const ROUTE = 'integration.health';

    public function render()
    {
        return view('livewire.integration.health', ['rows' => $this->api()->get('integration/health')['data'] ?? []]);
    }
}

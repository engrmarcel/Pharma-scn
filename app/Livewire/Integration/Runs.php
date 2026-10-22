<?php

namespace App\Livewire\Integration;

use App\Livewire\WebPage;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

/** File and batch runs, inbound and outbound, with their counts. */
#[Title('Integration runs')]
class Runs extends WebPage
{
    public const ROUTE = 'integration.runs';

    #[Url]
    public string $connector = '';

    protected array $filters = ['connector'];

    public function render()
    {
        return view('livewire.integration.runs', [
            'rows' => $this->api()->get('integration/runs', ['connector_id' => $this->connector])['data'] ?? [],
            'connectors' => collect($this->read('integration/connectors')['data'] ?? [])->pluck('name', 'id'),
        ]);
    }
}

<?php

namespace App\Livewire\Traceability;

use App\Livewire\WebPage;
use App\Support\Web\ApiError;
use Livewire\Attributes\Title;

/** Re-check the organization's event hash chain and export events as a GS1 EPCIS 2.0 document. */
#[Title('Trace integrity')]
class Integrity extends WebPage
{
    public const ROUTE = 'trace.integrity';

    public ?array $result = null;

    public string $from = '';

    public string $to = '';

    public function check(): void
    {
        $this->result = $this->attempt(fn ($api) => $api->get('trace/integrity'))['data'] ?? null;
    }

    public function export()
    {
        try {
            $json = $this->api()->download('trace/export/epcis', ['from' => $this->from, 'to' => $this->to]);
        } catch (ApiError $e) {
            $this->notify($e->getMessage(), 'error');

            return null;
        }

        return response()->streamDownload(fn () => print ($json), 'epcis-'.business_today()->toDateString().'.jsonld', ['Content-Type' => 'application/ld+json']);
    }

    public function render()
    {
        return view('livewire.traceability.integrity');
    }
}

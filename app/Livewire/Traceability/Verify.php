<?php

namespace App\Livewire\Traceability;

use App\Livewire\WebPage;
use Livewire\Attributes\Title;

/** Scan or type a pack code (GS1 DataMatrix, QR link or token) to check it; record dispensing of a serialised pack. */
#[Title('Verify a pack')]
class Verify extends WebPage
{
    public const ROUTE = 'trace.verify';

    public string $code = '';

    public ?array $result = null;

    public function check(): void
    {
        $result = $this->submit('scan', 'trace/scan', ['code' => trim($this->code)]);
        $this->result = $result['data'] ?? null;
    }

    public function dispense(): void
    {
        $result = $this->attempt(fn ($api) => $api->post('trace/dispense', ['code' => trim($this->code)]), 'Recorded as dispensed. Scanning this serial again will now warn.');
        if ($result !== null) {
            $this->check();
        }
    }

    public function render()
    {
        return view('livewire.traceability.verify', ['canDispense' => $this->can('pos.sell', 'pos.dispense.prescription', 'traceability.manage')]);
    }
}

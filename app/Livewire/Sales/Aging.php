<?php

namespace App\Livewire\Sales;

use App\Livewire\WebPage;
use Livewire\Attributes\Title;

/** Receivables aging: open invoice balances by days past due. */
#[Title('Receivables aging')]
class Aging extends WebPage
{
    public const ROUTE = 'sales.aging';

    public string $asOf = '';

    public function render()
    {
        $data = $this->read('sales/receivables-aging', ['as_of' => $this->asOf], ['data' => null])['data'];

        return view('livewire.sales.aging', ['aging' => $data, 'currency' => $this->currency()]);
    }
}

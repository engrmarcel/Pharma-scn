<?php

namespace App\Livewire\Marketplace;

use App\Livewire\WebPage;
use Livewire\Attributes\Title;

/** Marketplace fees accrued on the organization's invoiced sales. */
#[Title('Marketplace fees')]
class Fees extends WebPage
{
    public const ROUTE = 'marketplace.fees';

    public string $from = '';

    public string $to = '';

    public function render()
    {
        $data = $this->read('marketplace/fees', ['from' => $this->from, 'to' => $this->to], ['data' => null, 'meta' => []]);

        return view('livewire.marketplace.fees', ['rows' => $data['data'], 'meta' => $data['meta'] ?? [], 'currency' => $this->currency()]);
    }
}

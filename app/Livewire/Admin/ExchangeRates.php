<?php

namespace App\Livewire\Admin;

use App\Livewire\WebPage;
use Livewire\Attributes\Title;

/** Exchange rates used only to present platform-wide totals in one currency; never in tenant books. */
#[Title('Exchange rates')]
class ExchangeRates extends WebPage
{
    public const ROUTE = 'admin.exchange-rates';

    public ?array $rate = null;

    public function start(): void
    {
        $this->resetErrorBag();
        $this->rate = ['base_currency' => 'USD', 'quote_currency' => 'NGN', 'rate' => '', 'effective_date' => business_today()->toDateString(), 'source' => ''];
    }

    public function save(): void
    {
        if ($this->submit('rate', 'admin/exchange-rates', $this->rate, 'Exchange rate recorded.') !== null) {
            $this->rate = null;
        }
    }

    public function render()
    {
        return view('livewire.admin.exchange-rates', [
            'rates' => $this->read('admin/exchange-rates', [], ['data' => []])['data'],
            'currencies' => array_keys(config('currencies')),
        ]);
    }
}

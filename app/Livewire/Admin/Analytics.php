<?php

namespace App\Livewire\Admin;

use App\Livewire\WebPage;
use Livewire\Attributes\Title;

/** Network growth, verification, subscriptions and marketplace value by country, optionally converted to one currency. */
#[Title('Platform analytics')]
class Analytics extends WebPage
{
    public const ROUTE = 'admin.analytics';

    public string $from = '';

    public string $to = '';

    public string $currency = '';

    protected array $filters = ['from', 'to', 'currency'];

    public function mount(): void
    {
        $this->from = business_today()->startOfMonth()->toDateString();
        $this->to = business_today()->toDateString();
    }

    public function render()
    {
        return view('livewire.admin.analytics', [
            'data' => $this->read('admin/analytics/platform', array_filter(['from' => $this->from, 'to' => $this->to, 'reporting_currency' => $this->currency]), ['data' => null])['data'],
            'currencies' => array_keys(config('currencies')),
        ]);
    }
}

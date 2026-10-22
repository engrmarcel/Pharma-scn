<?php

namespace App\Livewire\Pos;

use App\Livewire\WebPage;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

/** POS reports for a date range: summary, products, cashiers, terminals, payment methods, refunds, shifts, batches sold. */
#[Title('POS reports')]
class Reports extends WebPage
{
    public const ROUTE = 'pos.reports';

    public const REPORTS = ['summary' => 'Summary', 'by-product' => 'Products', 'by-cashier' => 'Cashiers', 'by-terminal' => 'Terminals',
        'by-payment-method' => 'Payment methods', 'refunds' => 'Refunds', 'shifts' => 'Shifts', 'batches' => 'Batches sold'];

    #[Url]
    public string $report = 'summary';

    public string $from = '';

    public string $to = '';

    public function mount(): void
    {
        $this->from = business_today()->startOfMonth()->toDateString();
        $this->to = business_today()->toDateString();
    }

    public function render()
    {
        $data = $this->read("pos/reports/{$this->report}", ['from' => $this->from, 'to' => $this->to], ['data' => null])['data'];
        $rows = $this->report === 'summary' ? [] : collect($data['rows'] ?? [])->map(fn ($r) => (array) $r)->all();

        return view('livewire.pos.reports', [
            'data' => $data,
            'rows' => $rows,
            'reports' => self::REPORTS,
            'names' => $this->report === 'batches' ? $this->productNames(array_column($rows, 'product_id')) : [],
            'currency' => $this->currency(),
        ]);
    }
}

<?php

namespace App\Livewire\Finance;

use App\Livewire\WebPage;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

/** Management reports from the ledger: trial balance, profit and loss, balance sheet, account ledger, payables aging. */
#[Title('Financial reports')]
class Reports extends WebPage
{
    public const ROUTE = 'finance.reports';

    #[Url]
    public string $report = 'profit-and-loss';

    public string $from = '';

    public string $to = '';

    public string $asOf = '';

    #[Url]
    public string $account = '';

    public function mount(): void
    {
        $this->from = business_today()->startOfMonth()->toDateString();
        $this->to = business_today()->toDateString();
        $this->asOf = $this->to;
    }

    public function render()
    {
        $query = in_array($this->report, ['profit-and-loss', 'account-ledger'], true) ? ['from' => $this->from, 'to' => $this->to] : ['as_of' => $this->asOf];
        if ($this->report === 'account-ledger') {
            $query['account_id'] = $this->account;
        }
        $data = $this->report === 'account-ledger' && $this->account === '' ? null : $this->read("finance/reports/{$this->report}", $query, ['data' => null]);

        return view('livewire.finance.reports', [
            'data' => $data['data'] ?? null,
            'notice' => $data['meta']['notice'] ?? null,
            'accounts' => collect($this->read('finance/accounts')['data'] ?? [])->mapWithKeys(fn ($a) => [$a['id'] => $a['code'].' · '.$a['name']]),
            'currency' => $this->currency(),
        ]);
    }
}

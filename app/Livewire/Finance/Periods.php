<?php

namespace App\Livewire\Finance;

use App\Livewire\WebPage;
use Livewire\Attributes\Title;

/** Accounting periods: close a month to stop postings into it; reopening needs a reason. */
#[Title('Accounting periods')]
class Periods extends WebPage
{
    public const ROUTE = 'finance.periods';

    public ?array $changing = null;

    public function start(string $id, string $action, string $name): void
    {
        $this->resetErrorBag();
        $this->changing = ['id' => $id, 'action' => $action, 'name' => $name, 'reason' => ''];
    }

    public function apply(): void
    {
        $c = $this->changing;
        if ($this->submit('changing', "finance/periods/{$c['id']}/{$c['action']}", array_filter(['reason' => $c['reason']]), $c['name'].($c['action'] === 'close' ? ' closed.' : ' reopened.')) !== null) {
            $this->changing = null;
        }
    }

    public function render()
    {
        return view('livewire.finance.periods', ['periods' => $this->api()->get('finance/periods')['data'] ?? [], 'canManage' => $this->can('finance.periods.manage')]);
    }
}

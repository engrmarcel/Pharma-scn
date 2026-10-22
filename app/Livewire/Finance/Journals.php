<?php

namespace App\Livewire\Finance;

use App\Livewire\WebPage;
use Livewire\Attributes\Title;

/** Journal entries: automatic postings from operations, manual entries and reversals. */
#[Title('Journal entries')]
class Journals extends WebPage
{
    public const ROUTE = 'finance.journals';

    public string $from = '';

    public string $to = '';

    public string $source = '';

    public ?string $openId = null;

    public ?array $entry = null;

    public string $reverseReason = '';

    protected array $filters = ['from', 'to', 'source'];

    public function create(): void
    {
        $this->resetErrorBag();
        $this->entry = ['entry_date' => business_today()->toDateString(), 'description' => '', 'lines' => [
            ['account_id' => '', 'debit' => '', 'credit' => '', 'memo' => ''], ['account_id' => '', 'debit' => '', 'credit' => '', 'memo' => ''],
        ]];
    }

    public function addLine(): void
    {
        $this->entry['lines'][] = ['account_id' => '', 'debit' => '', 'credit' => '', 'memo' => ''];
    }

    public function removeLine(int $i): void
    {
        unset($this->entry['lines'][$i]);
        $this->entry['lines'] = array_values($this->entry['lines']);
    }

    public function save(): void
    {
        $data = $this->entry;
        $data['lines'] = array_map(fn ($l) => array_filter($l, fn ($v) => $v !== ''), $data['lines']);
        if ($this->submit('entry', 'finance/journal-entries', $data, 'Journal entry posted.') !== null) {
            $this->entry = null;
        }
    }

    public function reverse(): void
    {
        if ($this->submit('reverse', "finance/journal-entries/{$this->openId}/reverse", ['reason' => $this->reverseReason], 'Reversing entry posted.') !== null) {
            $this->reverseReason = '';
            $this->openId = null;
        }
    }

    public function render()
    {
        $result = $this->api()->get('finance/journal-entries', ['from' => $this->from, 'to' => $this->to, 'source_type' => $this->source, 'page' => $this->page]);
        $rows = $result['data'] ?? [];
        $accounts = collect($this->read('finance/accounts')['data'] ?? []);

        return view('livewire.finance.journals', [
            'rows' => $rows,
            'meta' => $result,
            'detail' => $this->openId ? collect($rows)->firstWhere('id', $this->openId) : null,
            'accounts' => $accounts->where('status', 'active')->mapWithKeys(fn ($a) => [$a['id'] => $a['code'].' · '.$a['name']]),
            'accountNames' => $accounts->mapWithKeys(fn ($a) => [$a['id'] => $a['code'].' · '.$a['name']]),
            'canPost' => $this->can('finance.journal.post'),
        ]);
    }
}

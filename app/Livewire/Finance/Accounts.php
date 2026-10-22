<?php

namespace App\Livewire\Finance;

use App\Domain\Finance\Models\Account;
use App\Livewire\WebPage;
use Livewire\Attributes\Title;

/** The chart of accounts: system accounts used by automatic postings, plus the organization's own. */
#[Title('Chart of accounts')]
class Accounts extends WebPage
{
    public const ROUTE = 'finance.accounts';

    public ?array $account = null;

    public function create(): void
    {
        $this->resetErrorBag();
        $this->account = ['id' => null, 'code' => '', 'name' => '', 'type' => 'expense', 'parent_id' => '', 'status' => 'active'];
    }

    public function edit(array $row): void
    {
        $this->resetErrorBag();
        $this->account = ['id' => $row['id'], 'code' => $row['code'], 'name' => $row['name'], 'type' => $row['type'], 'parent_id' => $row['parent_id'] ?? '', 'status' => $row['status'], 'system' => (bool) $row['system_key']];
    }

    public function save(): void
    {
        $a = $this->account;
        $result = $a['id']
            ? $this->submit('account', "finance/accounts/{$a['id']}", ['code' => $a['code'], 'name' => $a['name'], 'status' => $a['status']], 'Account updated.', 'patch')
            : $this->submit('account', 'finance/accounts', array_filter(['code' => $a['code'], 'name' => $a['name'], 'type' => $a['type'], 'parent_id' => $a['parent_id']]), 'Account created.');
        if ($result !== null) {
            $this->account = null;
        }
    }

    public function render()
    {
        $accounts = $this->api()->get('finance/accounts')['data'] ?? [];

        return view('livewire.finance.accounts', [
            'groups' => collect($accounts)->groupBy('type'),
            'parents' => collect($accounts)->mapWithKeys(fn ($a) => [$a['id'] => $a['code'].' · '.$a['name']]),
            'types' => Account::TYPES,
            'canManage' => $this->can('finance.accounts.manage'),
        ]);
    }
}

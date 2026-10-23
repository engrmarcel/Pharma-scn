<?php

namespace App\Livewire\Admin;

use App\Livewire\WebPage;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

/** Platform user accounts: suspend (ends sessions and tokens), reactivate, reset two-factor for a lost device. */
#[Title('Users')]
class Users extends WebPage
{
    public const ROUTE = 'admin.users';

    #[Url]
    public string $search = '';

    public ?array $acting = null;

    protected array $filters = ['search'];

    public function start(int $id, string $name, string $action): void
    {
        $this->resetErrorBag();
        $this->acting = ['id' => $id, 'name' => $name, 'action' => $action, 'reason' => ''];
    }

    public function apply(): void
    {
        $a = $this->acting;
        $uri = ['suspend' => "admin/users/{$a['id']}/suspend", 'reactivate' => "admin/users/{$a['id']}/reactivate", 'mfa' => "admin/users/{$a['id']}/mfa-reset"][$a['action']];
        $done = ['suspend' => 'Account suspended.', 'reactivate' => 'Account reactivated.', 'mfa' => 'Two-factor reset: they set it up again at next sign-in.'][$a['action']];
        if ($this->submit('acting', $uri, array_filter(['reason' => $a['reason']]), $done) !== null) {
            $this->acting = null;
        }
    }

    public function render()
    {
        $result = $this->api()->get('admin/users', ['q' => trim($this->search), 'page' => $this->page]);

        return view('livewire.admin.users', ['rows' => $result['data'] ?? [], 'meta' => $result]);
    }
}

<?php

namespace App\Livewire\Crm;

use App\Livewire\Concerns\PicksProducts;
use App\Livewire\WebPage;
use Livewire\Attributes\Title;

/** Approved product messages for detailing (draft, approve, retire), and what representatives presented. */
#[Title('Detailing')]
class Detailing extends WebPage
{
    use PicksProducts;

    public const ROUTE = 'crm.detailing';

    public string $tab = 'messages';

    public string $status = '';

    public ?array $form = null;

    public function create(): void
    {
        $this->resetErrorBag();
        $this->form = ['id' => null, 'product_id' => '', 'product_name' => null, 'title' => '', 'key_message' => '', 'approval_reference' => '', 'valid_until' => ''];
    }

    public function edit(array $m, string $productName): void
    {
        $this->resetErrorBag();
        $this->form = ['id' => $m['id'], 'product_id' => $m['product_id'], 'product_name' => $productName, 'title' => $m['title'], 'key_message' => $m['key_message'],
            'approval_reference' => $m['approval_reference'] ?? '', 'valid_until' => $m['valid_until'] ? substr($m['valid_until'], 0, 10) : ''];
    }

    protected function productPicked(string $target, array $product): void
    {
        $this->form['product_id'] = $product['product_id'];
        $this->form['product_name'] = $product['name'];
    }

    public function save(): void
    {
        $f = $this->form;
        $data = array_filter(collect($f)->only(['product_id', 'title', 'key_message', 'approval_reference', 'valid_until'])->all(), fn ($v) => $v !== '');
        $result = $f['id'] ? $this->submit('form', "crm/detailing-messages/{$f['id']}", collect($data)->except('product_id')->all(), 'Message updated.', 'patch')
            : $this->submit('form', 'crm/detailing-messages', $data, 'Message drafted. Approve it to make it available to representatives.');
        if ($result !== null) {
            $this->form = null;
        }
    }

    public function act(string $id, string $action): void
    {
        $this->attempt(fn ($api) => $api->post("crm/detailing-messages/{$id}/{$action}"), $action === 'approve' ? 'Message approved.' : 'Message retired.');
    }

    public function render()
    {
        $messages = $this->tab === 'messages' ? ($this->read('crm/detailing-messages', ['status' => $this->status])['data'] ?? []) : [];
        $records = $this->tab === 'records' ? $this->read('crm/detailing-records', ['page' => $this->page]) : [];

        return view('livewire.crm.detailing', [
            'messages' => $messages,
            'records' => $records['data'] ?? [],
            'meta' => $records,
            'names' => $this->productNames(array_merge(array_column($messages, 'product_id'), array_column($records['data'] ?? [], 'product_id'))),
            'customers' => $this->tab === 'records' ? collect($this->read('field-force/customers')['data'] ?? [])->pluck('name', 'id') : collect(),
            'canManage' => $this->can('crm.detailing.manage'),
        ]);
    }
}

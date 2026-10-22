<?php

namespace App\Livewire\Crm;

use App\Domain\Crm\Models\CrmInteraction;
use App\Livewire\WebPage;
use Illuminate\Support\Str;
use Livewire\Attributes\Title;

/** Calls, emails, meetings and messages with customers. Personal channels respect the contact's consent. */
#[Title('Interactions')]
class Interactions extends WebPage
{
    public const ROUTE = 'crm.interactions';

    public string $customer = '';

    public string $channel = '';

    public ?array $form = null;

    protected array $filters = ['customer', 'channel'];

    public function create(): void
    {
        $this->resetErrorBag();
        $this->form = ['customer_id' => $this->customer, 'contact_id' => '', 'channel' => 'call', 'direction' => 'outbound', 'subject' => '', 'summary' => '', 'occurred_at' => now()->format('Y-m-d\TH:i')];
    }

    public function save(): void
    {
        if ($this->submit('form', 'crm/interactions', array_filter($this->form, fn ($v) => $v !== '') + ['client_reference' => 'web-'.Str::ulid()], 'Interaction logged.') !== null) {
            $this->form = null;
        }
    }

    public function render()
    {
        $result = $this->api()->get('crm/interactions', ['customer_id' => $this->customer, 'channel' => $this->channel, 'page' => $this->page]);
        $customerId = $this->form['customer_id'] ?? '';

        return view('livewire.crm.interactions', [
            'rows' => $result['data'] ?? [],
            'meta' => $result,
            'customers' => collect($this->read('field-force/customers')['data'] ?? [])->pluck('name', 'id'),
            'people' => collect($this->read('members')['data'] ?? [])->mapWithKeys(fn ($m) => [$m['user']['id'] => $m['user']['name']]),
            'contacts' => $customerId ? collect($this->read("crm/customers/{$customerId}/contacts")['data'] ?? [])->reject(fn ($c) => $c['anonymized']) : collect(),
            'channels' => CrmInteraction::CHANNELS,
            'canCapture' => $this->can('field_force.visits.capture'),
        ]);
    }
}

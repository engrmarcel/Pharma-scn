<?php

namespace App\Livewire\Crm;

use App\Domain\Crm\Models\Opportunity;
use App\Domain\Parties\Models\Customer;
use App\Livewire\WebPage;
use Illuminate\Support\Str;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

/** Opportunities: the pipeline by stage, with weighted value; leads convert into customers. */
#[Title('Opportunities')]
class Opportunities extends WebPage
{
    public const ROUTE = 'crm.opportunities';

    #[Url]
    public string $stage = '';

    public ?array $form = null;

    public ?array $converting = null;

    protected array $filters = ['stage'];

    public function create(): void
    {
        $this->resetErrorBag();
        $this->form = ['id' => null, 'customer_id' => '', 'lead_name' => '', 'title' => '', 'stage' => 'lead', 'expected_value' => '', 'probability' => '20', 'expected_close_date' => '', 'next_step' => '', 'next_step_date' => '', 'notes' => '', 'lost_reason' => ''];
    }

    public function edit(array $o): void
    {
        $this->resetErrorBag();
        $this->form = ['id' => $o['id']] + array_map(fn ($v) => $v === null ? '' : (string) $v, array_intersect_key($o, array_flip(['customer_id', 'lead_name', 'title', 'stage', 'expected_value', 'probability', 'expected_close_date', 'next_step', 'next_step_date', 'notes', 'lost_reason'])));
        foreach (['expected_close_date', 'next_step_date'] as $d) {
            $this->form[$d] = substr($this->form[$d], 0, 10);
        }
    }

    public function save(): void
    {
        $f = $this->form;
        $data = array_filter(collect($f)->except(['id'])->all(), fn ($v) => $v !== '');
        $result = $f['id']
            ? $this->submit('form', "crm/opportunities/{$f['id']}", collect($data)->except('customer_id')->all(), 'Opportunity updated.', 'patch')
            : $this->submit('form', 'crm/opportunities', collect($data)->except('lost_reason')->all() + ['client_reference' => 'web-'.Str::ulid()], 'Opportunity added.');
        if ($result !== null) {
            $this->form = null;
        }
    }

    public function startConvert(string $id, string $name): void
    {
        $this->resetErrorBag();
        $this->converting = ['id' => $id, 'code' => '', 'name' => $name, 'type' => 'pharmacy'];
    }

    public function convert(): void
    {
        $c = $this->converting;
        if ($this->submit('converting', "crm/opportunities/{$c['id']}/convert-customer", ['code' => $c['code'], 'name' => $c['name'], 'type' => $c['type']], 'Lead converted into a customer.') !== null) {
            $this->converting = null;
        }
    }

    public function render()
    {
        $result = $this->api()->get('crm/opportunities', ['stage' => $this->stage, 'page' => $this->page]);

        return view('livewire.crm.opportunities', [
            'rows' => $result['data'] ?? [],
            'meta' => $result,
            'pipeline' => $result['pipeline'] ?? [],
            'customers' => collect($this->read('field-force/customers')['data'] ?? [])->pluck('name', 'id'),
            'stages' => Opportunity::STAGES,
            'types' => Customer::TYPES,
            'currency' => $this->currency(),
            'canCapture' => $this->can('field_force.visits.capture'),
        ]);
    }
}

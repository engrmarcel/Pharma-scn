<?php

namespace App\Livewire\Crm;

use App\Domain\Parties\Models\Customer;
use App\Livewire\WebPage;
use Livewire\Attributes\Title;

/** Customer segments: by rule (refreshed on demand) or by hand. */
#[Title('Segments')]
class Segments extends WebPage
{
    public const ROUTE = 'crm.segments';

    public ?array $form = null;

    public ?string $openId = null;

    public string $customerSearch = '';

    public function create(): void
    {
        $this->resetErrorBag();
        $this->form = ['code' => '', 'name' => '', 'description' => '', 'type' => 'rule', 'customer_types' => [], 'tiers' => [], 'cities' => '', 'not_visited_days' => '', 'min_revenue_12m' => ''];
    }

    public function save(): void
    {
        $f = $this->form;
        $criteria = $f['type'] === 'rule' ? array_filter([
            'customer_types' => array_values($f['customer_types']) ?: null, 'tiers' => array_values($f['tiers']) ?: null,
            'cities' => $f['cities'] !== '' ? array_map('trim', explode(',', $f['cities'])) : null,
            'not_visited_days' => $f['not_visited_days'] !== '' ? (int) $f['not_visited_days'] : null,
            'min_revenue_12m' => $f['min_revenue_12m'] !== '' ? (float) $f['min_revenue_12m'] : null,
        ], fn ($v) => $v !== null) : null;
        $result = $this->submit('form', 'crm/segments', array_filter(['code' => $f['code'], 'name' => $f['name'], 'description' => $f['description'], 'type' => $f['type']]) + ['criteria' => $criteria], 'Segment created.');
        if ($result !== null) {
            $this->form = null;
            $this->openId = $result['data']['id'];
        }
    }

    public function refreshSegment(): void
    {
        $result = $this->attempt(fn ($api) => $api->post("crm/segments/{$this->openId}/refresh"));
        if ($result !== null) {
            $this->notify('Segment refreshed: '.$result['data']['members'].' customers.');
        }
    }

    public function changeMember(string $customerId, bool $add): void
    {
        $this->attempt(fn ($api) => $api->put("crm/segments/{$this->openId}/members", ['add' => $add ? [$customerId] : [], 'remove' => $add ? [] : [$customerId]]));
    }

    public function archive(): void
    {
        $this->attempt(fn ($api) => $api->patch("crm/segments/{$this->openId}", ['status' => 'archived']), 'Segment archived.');
    }

    public function render()
    {
        $segments = $this->api()->get('crm/segments')['data'] ?? [];
        $detail = $this->openId ? collect($segments)->firstWhere('id', $this->openId) : null;
        $members = $detail ? ($this->read("crm/segments/{$this->openId}/members")['data'] ?? []) : [];

        return view('livewire.crm.segments', [
            'segments' => $segments,
            'detail' => $detail,
            'members' => $members,
            'candidates' => $detail && $detail['type'] === 'manual' && mb_strlen(trim($this->customerSearch)) >= 2
                ? collect($this->read('crm/customers', ['q' => trim($this->customerSearch)])['data'] ?? [])->whereNotIn('id', array_column($members, 'id'))->take(10) : collect(),
            'customerTypes' => Customer::TYPES,
            'canManage' => $this->can('crm.segments.manage'),
        ]);
    }
}

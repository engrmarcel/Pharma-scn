<?php

namespace App\Livewire\Crm;

use App\Livewire\WebPage;
use Livewire\Attributes\Title;

/** CRM automation: follow-ups created for missed plan items, overdue visits and stale opportunities; handover of a representative's work. */
#[Title('CRM automation')]
class Automation extends WebPage
{
    public const ROUTE = 'crm.automation';

    public array $form = [];

    public ?array $lastRun = null;

    public array $handover = ['from_user_id' => '', 'to_user_id' => '', 'territory_id' => ''];

    public function mount(): void
    {
        $s = $this->api()->get('crm/automation/settings')['data'];
        $this->form = ['missed_plan_items' => (bool) $s['missed_plan_items'], 'overdue_visits' => (bool) $s['overdue_visits'],
            'stale_opportunity_days' => (string) ($s['stale_opportunity_days'] ?? ''), 'escalation_days' => (string) ($s['escalation_days'] ?? '')];
    }

    public function save(): void
    {
        $f = $this->form;
        $this->submit('form', 'crm/automation/settings', ['missed_plan_items' => (bool) $f['missed_plan_items'], 'overdue_visits' => (bool) $f['overdue_visits'],
            'stale_opportunity_days' => $f['stale_opportunity_days'] === '' ? null : (int) $f['stale_opportunity_days'],
            'escalation_days' => $f['escalation_days'] === '' ? null : (int) $f['escalation_days']], 'Automation settings saved.', 'patch');
    }

    public function run(): void
    {
        $this->lastRun = $this->attempt(fn ($api) => $api->post('crm/automation/run'), 'Automation ran.')['data'] ?? null;
    }

    public function transfer(): void
    {
        $h = $this->handover;
        $result = $this->submit('handover', 'crm/handover', array_filter(['from_user_id' => (int) $h['from_user_id'], 'to_user_id' => (int) $h['to_user_id'], 'territory_id' => $h['territory_id']]), 'Work handed over.');
        if ($result !== null) {
            $this->handover = ['from_user_id' => '', 'to_user_id' => '', 'territory_id' => ''];
        }
    }

    public function render()
    {
        return view('livewire.crm.automation', [
            'people' => collect($this->read('members')['data'] ?? [])->mapWithKeys(fn ($m) => [$m['user']['id'] => $m['user']['name']]),
            'territories' => collect($this->read('field-force/territories')['data'] ?? [])->pluck('name', 'id'),
            'canHandover' => $this->can('field_force.territories.manage'),
        ]);
    }
}

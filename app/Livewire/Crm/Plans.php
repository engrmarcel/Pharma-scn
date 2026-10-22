<?php

namespace App\Livewire\Crm;

use App\Livewire\WebPage;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

/** Visit plans: a representative plans calls for a period, submits, a supervisor approves; adherence is tracked. */
#[Title('Visit plans')]
class Plans extends WebPage
{
    public const ROUTE = 'crm.plans';

    #[Url]
    public string $status = '';

    #[Url(as: 'open')]
    public ?string $openId = null;

    public ?array $plan = null;

    public string $decisionNotes = '';

    protected array $filters = ['status'];

    public function create(): void
    {
        $start = business_today()->next('Monday');
        $this->resetErrorBag();
        $this->plan = ['period_start' => $start->toDateString(), 'period_end' => $start->copy()->addDays(4)->toDateString(), 'notes' => '', 'items' => []];
    }

    /** Add the customers suggested for the period (due, never visited, high tier). */
    public function suggest(): void
    {
        $suggested = $this->read('crm/visit-suggestions', ['from' => $this->plan['period_start'], 'to' => $this->plan['period_end']])['data'] ?? [];
        $existing = array_column($this->plan['items'], 'customer_id');
        foreach ($suggested as $s) {
            if (! in_array($s['customer_id'], $existing, true)) {
                $date = max($this->plan['period_start'], min($this->plan['period_end'], $s['due'] ?? $this->plan['period_start']));
                $this->plan['items'][] = ['customer_id' => $s['customer_id'], 'planned_date' => $date,
                    'objective' => mb_substr(implode('; ', array_map(fn ($r) => str_replace('_', ' ', is_array($r) ? ($r['reason'] ?? '') : $r), (array) ($s['reasons'] ?? []))), 0, 255)];
            }
        }
        if ($suggested === []) {
            $this->notify('No suggestions for this period.', 'warning');
        }
    }

    public function addItem(): void
    {
        $this->plan['items'][] = ['customer_id' => '', 'planned_date' => $this->plan['period_start'], 'objective' => ''];
    }

    public function removeItem(int $i): void
    {
        unset($this->plan['items'][$i]);
        $this->plan['items'] = array_values($this->plan['items']);
    }

    public function save(): void
    {
        $data = $this->plan;
        $data['items'] = array_map(fn ($i) => array_filter($i, fn ($v) => $v !== ''), $data['items']);
        $data['notes'] = $data['notes'] ?: null;
        $result = $this->submit('plan', 'crm/visit-plans', $data, 'Plan saved as a draft.');
        if ($result !== null) {
            $this->plan = null;
            $this->openId = $result['data']['id'];
        }
    }

    public function act(string $action): void
    {
        $body = match ($action) {
            'approve', 'reject' => ['notes' => $this->decisionNotes ?: null],
            'cancel' => ['reason' => $this->decisionNotes ?: 'Cancelled from the web'],
            default => [],
        };
        $done = ['submit' => 'Plan submitted for approval.', 'approve' => 'Plan approved.', 'reject' => 'Plan sent back.', 'cancel' => 'Plan cancelled.'];
        if ($this->submit('decision', "crm/visit-plans/{$this->openId}/{$action}", $body, $done[$action]) !== null) {
            $this->decisionNotes = '';
        }
    }

    public function render()
    {
        $result = $this->api()->get('crm/visit-plans', ['status' => $this->status, 'page' => $this->page]);
        $detail = $this->openId ? ($this->read("crm/visit-plans/{$this->openId}")['data'] ?? null) : null;

        return view('livewire.crm.plans', [
            'rows' => $result['data'] ?? [],
            'meta' => $result,
            'detail' => $detail,
            'customers' => collect($this->read('field-force/customers')['data'] ?? [])->pluck('name', 'id'),
            'people' => collect($this->read('members')['data'] ?? [])->mapWithKeys(fn ($m) => [$m['user']['id'] => $m['user']['name']]),
            'me' => auth()->id(),
            'can' => ['capture' => $this->can('field_force.visits.capture'), 'approve' => $this->can('crm.plans.approve')],
        ]);
    }
}

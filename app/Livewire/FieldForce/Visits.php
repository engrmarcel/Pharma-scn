<?php

namespace App\Livewire\FieldForce;

use App\Domain\FieldForce\Models\FieldVisit;
use App\Domain\FieldForce\Models\FieldVisitActivity;
use App\Livewire\WebPage;
use Illuminate\Support\Str;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

/**
 * Customer visits. Representatives normally capture visits in the field app with a
 * location fix; a visit logged here has no device location and stays unverified.
 */
#[Title('Field visits')]
class Visits extends WebPage
{
    public const ROUTE = 'field-force.visits';

    #[Url]
    public string $status = '';

    public string $representative = '';

    public string $from = '';

    public string $to = '';

    #[Url(as: 'open')]
    public ?string $openId = null;

    public ?array $log = null;

    protected array $filters = ['status', 'representative', 'from', 'to'];

    public function startLog(): void
    {
        $this->resetErrorBag();
        $this->log = ['customer_id' => '', 'visit_type' => 'routine', 'objectives' => '', 'discussion_notes' => '', 'outcome' => 'successful', 'next_action' => '', 'activity' => 'note', 'activity_notes' => ''];
    }

    public function saveLog(): void
    {
        $l = $this->log;
        $noFix = fn () => ['occurred_at' => now()->toIso8601String(), 'location_status' => 'unavailable', 'device' => ['platform' => 'web']];
        $visit = $this->submit('log', 'field-force/visits', ['client_reference' => 'web-'.Str::ulid(), 'customer_id' => $l['customer_id'], 'visit_type' => $l['visit_type'],
            'objectives' => $l['objectives'] ?: null, 'check_in' => $noFix()]);
        if ($visit === null) {
            return;
        }
        $id = $visit['data']['id'];
        $out = $this->submit('log', "field-force/visits/{$id}/check-out", array_filter([
            'check_out' => $noFix(), 'outcome' => $l['outcome'], 'discussion_notes' => $l['discussion_notes'] ?: null, 'next_action' => $l['next_action'] ?: null,
            'activities' => $l['activity_notes'] !== '' ? [['activity_type' => $l['activity'], 'notes' => $l['activity_notes']]] : null,
        ], fn ($v) => $v !== null), 'Visit logged.');
        if ($out !== null) {
            $this->log = null;
            $this->openId = $id;
        }
    }

    public function cancelVisit(string $reason): void
    {
        $this->attempt(fn ($api) => $api->post("field-force/visits/{$this->openId}/cancel", ['reason' => $reason ?: 'Cancelled from the web']), 'Visit cancelled.');
    }

    public function render()
    {
        $result = $this->api()->get('field-force/visits', ['status' => $this->status, 'representative_id' => $this->representative, 'from' => $this->from, 'to' => $this->to, 'page' => $this->page]);
        $rows = $result['data'] ?? [];
        $detail = $this->openId ? ($this->read("field-force/visits/{$this->openId}")['data'] ?? null) : null;
        $customers = collect($this->read('field-force/customers')['data'] ?? [])->pluck('name', 'id');
        $people = collect($this->read('members')['data'] ?? [])->mapWithKeys(fn ($m) => [$m['user']['id'] => $m['user']['name']]);

        return view('livewire.field-force.visits', [
            'rows' => $rows,
            'meta' => $result,
            'detail' => $detail,
            'customers' => $customers,
            'people' => $people,
            'names' => $this->productNames(collect($detail['activities'] ?? [])->pluck('product_id')),
            'types' => FieldVisit::TYPES,
            'outcomes' => FieldVisit::OUTCOMES,
            'activityTypes' => FieldVisitActivity::TYPES,
            'canCapture' => $this->can('field_force.visits.capture'),
        ]);
    }
}

<?php

namespace App\Livewire\FieldForce;

use App\Livewire\WebPage;
use Illuminate\Support\Str;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

/** Follow-ups promised to customers, with due dates; complete with an outcome or cancel. */
#[Title('Follow-ups')]
class FollowUps extends WebPage
{
    public const ROUTE = 'field-force.follow-ups';

    #[Url]
    public string $status = 'open';

    public string $dueBefore = '';

    public ?array $form = null;

    public ?array $closing = null;

    protected array $filters = ['status', 'dueBefore'];

    public function create(): void
    {
        $this->resetErrorBag();
        $this->form = ['customer_id' => '', 'title' => '', 'notes' => '', 'due_on' => business_today()->addDays(7)->toDateString(), 'assigned_to' => ''];
    }

    public function save(): void
    {
        $data = array_filter($this->form, fn ($v) => $v !== '') + ['client_reference' => 'web-'.Str::ulid()];
        if (isset($data['assigned_to'])) {
            $data['assigned_to'] = (int) $data['assigned_to'];
        }
        if ($this->submit('form', 'field-force/follow-ups', $data, 'Follow-up added.') !== null) {
            $this->form = null;
        }
    }

    public function start(string $id, string $action): void
    {
        $this->resetErrorBag();
        $this->closing = ['id' => $id, 'action' => $action, 'outcome' => ''];
    }

    public function finish(): void
    {
        $c = $this->closing;
        if ($this->submit('closing', "field-force/follow-ups/{$c['id']}/{$c['action']}", array_filter(['outcome' => $c['outcome']]), $c['action'] === 'complete' ? 'Follow-up done.' : 'Follow-up cancelled.') !== null) {
            $this->closing = null;
        }
    }

    public function render()
    {
        $result = $this->api()->get('field-force/follow-ups', ['status' => $this->status, 'due_before' => $this->dueBefore, 'page' => $this->page]);

        return view('livewire.field-force.follow-ups', [
            'rows' => $result['data'] ?? [],
            'meta' => $result,
            'customers' => collect($this->read('field-force/customers')['data'] ?? [])->pluck('name', 'id'),
            'people' => collect($this->read('members')['data'] ?? [])->mapWithKeys(fn ($m) => [$m['user']['id'] => $m['user']['name']]),
            'canCapture' => $this->can('field_force.visits.capture'),
            'today' => business_today()->toDateString(),
        ]);
    }
}

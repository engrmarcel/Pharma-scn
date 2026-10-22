<?php

namespace App\Livewire\Traceability;

use App\Domain\Traceability\Models\TraceEvent;
use App\Livewire\WebPage;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

/** The append-only event log: corrections and reversals are new events that point at the original. */
#[Title('Trace events')]
class Events extends WebPage
{
    public const ROUTE = 'trace.events';

    #[Url]
    public string $type = '';

    public string $from = '';

    public string $to = '';

    public ?string $openId = null;

    /** Correction or reversal being entered. */
    public ?array $amend = null;

    protected array $filters = ['type', 'from', 'to'];

    public function open(string $id): void
    {
        $this->openId = $id;
        $this->amend = null;
    }

    public function startAmend(string $mode, array $event): void
    {
        $this->resetErrorBag();
        $this->amend = ['mode' => $mode, 'reason' => '', 'quantity' => (string) ($event['quantity'] ?? ''), 'batch_number' => $event['batch_number'] ?? '',
            'event_time' => substr($event['event_time'], 0, 16), 'counterparty_name' => $event['counterparty_name'] ?? ''];
    }

    public function saveAmend(array $original): void
    {
        $a = $this->amend;
        if ($a['mode'] === 'reverse') {
            $result = $this->submit('amend', "trace/events/{$this->openId}/reverse", ['reason' => $a['reason']], 'Reversal recorded.');
        } else {
            $changes = [];
            foreach (['quantity', 'batch_number', 'counterparty_name'] as $k) {
                if ((string) ($original[$k] ?? '') !== $a[$k]) {
                    $changes[$k] = $a[$k] === '' ? null : $a[$k];
                }
            }
            if (substr($original['event_time'], 0, 16) !== $a['event_time']) {
                $changes['event_time'] = $a['event_time'];
            }
            $result = $this->submit('amend', "trace/events/{$this->openId}/correct", ['reason' => $a['reason'], 'changes' => $changes], 'Correction recorded.');
        }
        if ($result !== null) {
            $this->amend = null;
            $this->openId = $result['data']['id'];
        }
    }

    public function render()
    {
        $result = $this->api()->get('trace/events', ['event_type' => $this->type, 'from' => $this->from, 'to' => $this->to, 'page' => $this->page]);
        $rows = $result['data'] ?? [];
        $detail = $this->openId ? (collect($rows)->firstWhere('id', $this->openId) ?? ($this->read("trace/events/{$this->openId}")['data'] ?? null)) : null;

        return view('livewire.traceability.events', [
            'rows' => $rows,
            'meta' => $result['meta'] ?? [],
            'detail' => $detail,
            'types' => TraceEvent::TYPES,
            'names' => $this->productNames(array_merge(array_column($rows, 'product_id'), [$detail['product_id'] ?? null])),
            'canManage' => $this->can('traceability.manage'),
        ]);
    }
}

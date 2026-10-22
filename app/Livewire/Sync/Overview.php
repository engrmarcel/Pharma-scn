<?php

namespace App\Livewire\Sync;

use App\Domain\Sync\Models\SyncIssue;
use App\Livewire\WebPage;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

/** Offline sync: transactions from devices that conflicted or were rejected, business issues they raised, and per-device reconciliation. */
#[Title('Offline sync')]
class Overview extends WebPage
{
    public const ROUTE = 'sync.overview';

    #[Url]
    public string $tab = 'issues';

    public string $status = 'open';

    public string $type = '';

    public ?array $resolving = null;

    public string $from = '';

    public string $to = '';

    protected array $filters = ['tab', 'status', 'type'];

    public function mount(): void
    {
        $this->from = business_today()->subDays(7)->toDateString();
        $this->to = business_today()->toDateString();
    }

    public function startIssue(array $issue): void
    {
        $this->resetErrorBag();
        $this->resolving = ['kind' => 'issue', 'id' => $issue['id'], 'type' => $issue['type'], 'action' => 'acknowledge', 'notes' => '', 'batch_number' => '', 'expiry_date' => '', 'unit_cost' => ''];
    }

    public function startInbox(int $id): void
    {
        $this->resetErrorBag();
        $this->resolving = ['kind' => 'inbox', 'id' => $id, 'notes' => ''];
    }

    public function resolve(): void
    {
        $r = $this->resolving;
        $result = $r['kind'] === 'inbox'
            ? $this->submit('resolving', "sync/inbox/{$r['id']}/resolve", ['notes' => $r['notes']], 'Marked as resolved.')
            : $this->submit('resolving', "sync/issues/{$r['id']}/resolve", array_filter(collect($r)->only(['action', 'notes', 'batch_number', 'expiry_date', 'unit_cost'])->all(), fn ($v) => $v !== ''), 'Issue resolved.');
        if ($result !== null) {
            $this->resolving = null;
        }
    }

    public function render()
    {
        $data = match ($this->tab) {
            'inbox' => $this->read('sync/inbox', ['unresolved' => 1, 'page' => $this->page]),
            'reconciliation' => $this->read('sync/reconciliation', ['from' => $this->from, 'to' => $this->to]),
            default => $this->read('sync/issues', ['status' => $this->status, 'type' => $this->type, 'page' => $this->page]),
        };
        $rows = $this->tab === 'reconciliation' ? ($data['data']['devices'] ?? []) : ($data['data'] ?? []);

        return view('livewire.sync.overview', [
            'rows' => $rows,
            'meta' => $this->tab === 'reconciliation' ? [] : $data,
            'types' => SyncIssue::TYPES,
            'names' => $this->productNames(array_filter(array_column($rows, 'product_id'))),
            'canResolve' => $this->can('sync.reconcile'),
        ]);
    }
}

<?php

namespace App\Livewire\Analytics;

use App\Domain\Analytics\Models\Recommendation;
use App\Livewire\WebPage;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

/** Advisory recommendations: review the figures and assumptions, then accept (a reorder becomes a draft requisition) or dismiss. */
#[Title('Recommendations')]
class Recommendations extends WebPage
{
    public const ROUTE = 'analytics.recommendations';

    #[Url]
    public string $status = 'open';

    public string $type = '';

    public ?array $deciding = null;

    protected array $filters = ['status', 'type'];

    public function generate(): void
    {
        $r = $this->attempt(fn ($api) => $api->post('analytics/recommendations/generate'));
        if ($r !== null) {
            $this->notify('Recommendations refreshed: '.collect($r['data'])->map(fn ($v, $k) => str_replace('_', ' ', $k).' '.(is_array($v) ? count($v) : $v))->implode(', ').'.');
        }
    }

    public function start(array $rec, string $decision): void
    {
        $this->resetErrorBag();
        $this->deciding = ['id' => $rec['id'], 'title' => $rec['title'], 'decision' => $decision, 'type' => $rec['type'], 'notes' => '', 'reason' => '',
            'quantity' => (string) ($rec['figures']['suggested_quantity'] ?? ''), 'warehouse_id' => $rec['warehouse_id'] ?? '', 'needed_by' => ''];
    }

    public function decide(): void
    {
        $d = $this->deciding;
        $body = $d['decision'] === 'accept'
            ? array_filter(['notes' => $d['notes'], 'quantity' => $d['quantity'], 'warehouse_id' => $d['warehouse_id'], 'needed_by' => $d['needed_by']], fn ($v) => $v !== '')
            : ['reason' => $d['reason']];
        $r = $this->submit('deciding', "analytics/recommendations/{$d['id']}/{$d['decision']}", $body, $d['decision'] === 'accept' ? 'Accepted.' : 'Dismissed.');
        if ($r !== null) {
            $this->deciding = null;
            if (($r['data']['decision']['action_type'] ?? null) === 'purchase_requisition') {
                $this->notify('A draft requisition was created. Review it under Procurement.');
            }
        }
    }

    public function render()
    {
        $rows = $this->api()->get('analytics/recommendations', ['status' => $this->status, 'type' => $this->type])['data'] ?? [];

        return view('livewire.analytics.recommendations', [
            'rows' => $rows,
            'types' => Recommendation::TYPES,
            'names' => $this->productNames(array_column($rows, 'product_id')),
            'warehouses' => $this->warehouseOptions(),
        ]);
    }
}

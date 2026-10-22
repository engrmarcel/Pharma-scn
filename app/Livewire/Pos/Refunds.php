<?php

namespace App\Livewire\Pos;

use App\Livewire\WebPage;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

/** POS refunds: request one against a completed sale; supervisors approve or reject. */
#[Title('POS refunds')]
class Refunds extends WebPage
{
    public const ROUTE = 'pos.refunds';

    #[Url]
    public string $status = 'pending_approval';

    public string $saleNumber = '';

    /** Refund request being prepared: sale, reason, method, lines (line id => [quantity, restock_state]). */
    public ?array $request = null;

    public ?string $deciding = null;

    public string $decisionNotes = '';

    protected array $filters = ['status'];

    public function findSale(): void
    {
        $this->resetErrorBag();
        $sale = collect($this->read('pos/sales', ['number' => $this->saleNumber])['data'] ?? [])->first();
        if ($sale === null) {
            $this->addError('saleNumber', 'No sale with that number.');

            return;
        }
        $full = $this->api()->get("pos/sales/{$sale['id']}")['data'];
        $this->request = ['sale' => $full, 'reason' => '', 'refund_method' => 'cash',
            'lines' => collect($full['lines'])->mapWithKeys(fn ($l) => [$l['id'] => ['quantity' => '', 'restock_state' => 'quarantined']])->all()];
    }

    public function sendRequest(): void
    {
        $lines = collect($this->request['lines'])->filter(fn ($l) => $l['quantity'] !== '' && (float) $l['quantity'] > 0)
            ->map(fn ($l, $id) => ['pos_sale_line_id' => $id] + $l)->values()->all();
        $result = $this->submit('refund', "pos/sales/{$this->request['sale']['id']}/refunds",
            ['reason' => $this->request['reason'], 'refund_method' => $this->request['refund_method'], 'lines' => $lines], 'Refund requested.');
        if ($result !== null) {
            $this->request = null;
            $this->saleNumber = '';
        }
    }

    public function decide(string $id, string $decision): void
    {
        if ($this->submit('decision', "pos/refunds/{$id}/{$decision}", ['notes' => $this->decisionNotes ?: null], $decision === 'approve' ? 'Refund approved.' : 'Refund rejected.') !== null) {
            $this->deciding = null;
            $this->decisionNotes = '';
        }
    }

    public function render()
    {
        $canApprove = $this->can('pos.refund.approve');
        $result = $canApprove ? $this->api()->get('pos/refunds', ['status' => $this->status, 'page' => $this->page]) : [];

        return view('livewire.pos.refunds', [
            'rows' => $result['data'] ?? [],
            'meta' => $result,
            'names' => $this->productNames(collect($this->request['sale']['lines'] ?? [])->pluck('product_id')),
            'canApprove' => $canApprove,
            'canRequest' => $this->can('pos.refund.request'),
        ]);
    }
}

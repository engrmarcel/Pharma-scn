<?php

namespace App\Livewire\Pos;

use App\Livewire\WebPage;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

/** Cashier shifts: cash counts and variances to review; supervisors can record cash movements or close a shift. */
#[Title('POS shifts')]
class Shifts extends WebPage
{
    public const ROUTE = 'pos.shifts';

    #[Url]
    public string $status = 'closed';

    public ?string $openId = null;

    public string $notes = '';

    public array $cash = ['amount' => '', 'reason' => ''];

    public string $countedCash = '';

    protected array $filters = ['status'];

    public function open(string $id): void
    {
        $this->openId = $id;
        $this->notes = '';
        $this->countedCash = '';
        $this->cash = ['amount' => '', 'reason' => ''];
        $this->resetErrorBag();
    }

    public function review(): void
    {
        $this->submit('review', "pos/shifts/{$this->openId}/review", ['notes' => $this->notes ?: null], 'Shift reviewed.');
    }

    public function cashMovement(): void
    {
        if ($this->submit('cash', "pos/shifts/{$this->openId}/cash-movements", $this->cash, 'Cash movement recorded.') !== null) {
            $this->cash = ['amount' => '', 'reason' => ''];
        }
    }

    public function close(): void
    {
        $this->submit('close', "pos/shifts/{$this->openId}/close", ['counted_cash' => $this->countedCash, 'notes' => $this->notes ?: null], 'Shift closed.');
    }

    public function render()
    {
        $result = $this->api()->get('pos/shifts', ['status' => $this->status, 'page' => $this->page]);
        $detail = $this->openId ? ($this->read("pos/shifts/{$this->openId}")['data'] ?? null) : null;
        $terminals = collect($this->read('pos/terminals')['data'] ?? [])->mapWithKeys(fn ($t) => [$t['id'] => $t['code'].' · '.$t['name']]);

        return view('livewire.pos.shifts', [
            'rows' => $result['data'] ?? [],
            'meta' => $result,
            'detail' => $detail,
            'detailRow' => $this->openId ? collect($result['data'] ?? [])->firstWhere('id', $this->openId) : null,
            'sales' => $detail ? ($this->read('pos/sales', ['pos_shift_id' => $detail['id']])['data'] ?? []) : [],
            'terminals' => $terminals,
            'currency' => $this->currency(),
            'can' => ['review' => $this->can('pos.shift.review'), 'cash' => $this->can('pos.cash.manage')],
        ]);
    }
}

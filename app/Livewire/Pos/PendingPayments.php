<?php

namespace App\Livewire\Pos;

use App\Domain\Pos\Models\PosSale;
use App\Livewire\WebPage;
use Livewire\Attributes\Title;

/** Card and transfer payments taken on a standalone terminal, confirmed against the settlement report. */
#[Title('Pending payments')]
class PendingPayments extends WebPage
{
    public const ROUTE = 'pos.payments';

    public function confirm(string $id): void
    {
        $this->attempt(fn ($api) => $api->post("pos/payments/{$id}/confirm"), 'Payment confirmed.');
    }

    public function render()
    {
        $payments = $this->api()->get('pos/payments/pending')['data'] ?? [];
        $sales = PosSale::query()->whereIn('id', array_column($payments, 'pos_sale_id'))->pluck('number', 'id');

        return view('livewire.pos.pending-payments', ['payments' => $payments, 'sales' => $sales]);
    }
}

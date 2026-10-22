<?php

namespace App\Livewire\Marketplace;

use App\Domain\Organizations\Models\Organization;
use App\Livewire\WebPage;
use App\Support\Tenancy\TenantContext;
use Livewire\Attributes\Title;

/** Disputes on marketplace orders: messages between the parties, escalation to the platform, resolution by the supplier. */
#[Title('Marketplace disputes')]
class Disputes extends WebPage
{
    public const ROUTE = 'marketplace.disputes';

    public ?string $openId = null;

    public string $message = '';

    public ?array $resolving = null;

    public function send(): void
    {
        if ($this->submit('message', "marketplace/disputes/{$this->openId}/messages", ['body' => $this->message]) !== null) {
            $this->message = '';
        }
    }

    public function escalate(): void
    {
        $this->submit('message', "marketplace/disputes/{$this->openId}/escalate", ['reason' => $this->message ?: 'No agreement between the parties'], 'Escalated to platform staff.');
        $this->message = '';
    }

    public function startResolve(): void
    {
        $this->resetErrorBag();
        $this->resolving = ['resolution' => 'replacement', 'notes' => ''];
    }

    public function resolve(): void
    {
        if ($this->submit('resolving', "marketplace/disputes/{$this->openId}/resolve", $this->resolving, 'Dispute resolved.') !== null) {
            $this->resolving = null;
        }
    }

    public function render()
    {
        $rows = $this->api()->get('marketplace/disputes')['data'] ?? [];
        $me = app(TenantContext::class)->id();
        $orgs = Organization::query()->whereIn('id', collect($rows)->flatMap(fn ($d) => [$d['buyer_organization_id'], $d['supplier_organization_id']])->unique())->pluck('name', 'id');

        return view('livewire.marketplace.disputes', [
            'rows' => $rows, 'me' => $me, 'orgs' => $orgs,
            'detail' => $this->openId ? ($this->read("marketplace/disputes/{$this->openId}")['data'] ?? null) : null,
        ]);
    }
}

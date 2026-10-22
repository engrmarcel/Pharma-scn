<?php

namespace App\Livewire\Integration;

use App\Livewire\WebPage;
use Livewire\Attributes\Title;

/** Outbound webhooks: subscriptions to platform events, signed deliveries with retries, and the event outbox. */
#[Title('Webhooks')]
class Webhooks extends WebPage
{
    public const ROUTE = 'integration.webhooks';

    public string $tab = 'subscriptions';

    public ?string $openId = null;

    public ?array $form = null;

    public ?array $secret = null;

    public string $deliveryStatus = '';

    protected array $filters = ['tab', 'deliveryStatus'];

    public function create(): void
    {
        $this->resetErrorBag();
        $this->form = ['name' => '', 'url' => 'https://', 'event_types' => []];
    }

    public function save(): void
    {
        $r = $this->submit('form', 'integration/webhooks', ['name' => $this->form['name'], 'url' => $this->form['url'], 'event_types' => array_values($this->form['event_types'])], 'Subscription created.');
        if ($r !== null) {
            $this->form = null;
            $this->openId = $r['data']['id'];
            $this->secret = ['value' => $r['data']['secret'], 'notice' => $r['data']['notice']];
        }
    }

    public function open(string $id): void
    {
        $this->openId = $id;
        $this->secret = null;
    }

    public function act(string $action): void
    {
        match ($action) {
            'secret' => $this->secret = ($r = $this->attempt(fn ($api) => $api->post("integration/webhooks/{$this->openId}/secret"))) ? ['value' => $r['data']['secret'], 'notice' => $r['data']['notice']] : null,
            'test' => $this->attempt(fn ($api) => $api->post("integration/webhooks/{$this->openId}/test"), 'Test event queued for delivery.'),
            default => $this->attempt(fn ($api) => $api->post("integration/webhooks/{$this->openId}/{$action}"), 'Subscription '.['activate' => 'activated', 'pause' => 'paused', 'disable' => 'disabled'][$action].'.'),
        };
    }

    public function redeliver(string $deliveryId): void
    {
        $this->attempt(fn ($api) => $api->post("integration/webhook-deliveries/{$deliveryId}/redeliver"), 'Delivery queued again.');
    }

    public function render()
    {
        $subs = $this->tab === 'subscriptions' ? ($this->api()->get('integration/webhooks')['data'] ?? []) : [];
        $detail = $this->openId ? ($this->read("integration/webhooks/{$this->openId}")['data'] ?? null) : null;
        $deliveries = $detail ? $this->read("integration/webhooks/{$this->openId}/deliveries", ['status' => $this->deliveryStatus]) : [];
        $outbox = $this->tab === 'outbox' ? $this->read('integration/outbox', ['page' => $this->page]) : [];

        return view('livewire.integration.webhooks', [
            'subs' => $subs,
            'detail' => $detail,
            'deliveries' => $deliveries['data'] ?? [],
            'outbox' => $outbox['data'] ?? [],
            'meta' => $outbox['meta'] ?? [],
            'eventTypes' => $this->form ? ($this->read('integration/webhooks/event-types')['data'] ?? []) : [],
            'canManage' => $this->can('integrations.manage'),
        ]);
    }
}

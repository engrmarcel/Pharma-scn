<?php

namespace App\Console\Commands;

use App\Domain\Integration\Jobs\ProcessIntegrationMessage;
use App\Domain\Integration\Models\IntegrationMessage;
use App\Domain\Integration\Models\WebhookDelivery;
use App\Domain\Integration\Webhooks\DeliverWebhook;
use App\Domain\Organizations\Models\Organization;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Safety net for the integration inbox (runs every minute): queues messages
 * whose retry is due, and returns messages stuck in "processing" (a worker
 * died mid-way) to the queue. Processing claims each message, so a message
 * that is also queued elsewhere is never applied twice.
 */
class ProcessDueIntegrationMessages extends Command
{
    protected $signature = 'integration:process-due {--limit=500}';

    protected $description = 'Queue integration inbox messages and webhook deliveries that are due for (re)processing';

    public function handle(TenantContext $tenant): int
    {
        $stale = now()->subMinutes(config('integrations.stale_processing_minutes'));
        $released = IntegrationMessage::acrossTenants()->where('status', 'processing')->where('processing_started_at', '<', $stale)
            ->update(['status' => 'received', 'next_attempt_at' => null, 'updated_at' => now()]);

        $due = IntegrationMessage::acrossTenants()
            ->join('integration_connectors as c', 'c.id', '=', 'integration_inbox.connector_id')
            ->where('c.status', 'active')
            ->where('integration_inbox.status', 'received')
            ->where(fn ($q) => $q->whereNull('integration_inbox.next_attempt_at')->orWhere('integration_inbox.next_attempt_at', '<=', now()))
            // Fresh messages are queued at intake; only pick up ones that waited at least a minute.
            ->where(fn ($q) => $q->whereNotNull('integration_inbox.next_attempt_at')->orWhere('integration_inbox.updated_at', '<=', now()->subMinute()))
            ->orderBy('integration_inbox.id')->limit((int) $this->option('limit'))
            ->get(['integration_inbox.id', 'integration_inbox.organization_id']);

        $organizations = Organization::query()->whereIn('id', $due->pluck('organization_id')->unique())->get()->keyBy('id');
        foreach ($due as $message) {
            $tenant->runAs($organizations[$message->organization_id], fn () => ProcessIntegrationMessage::dispatch($message->id));
        }

        // Webhooks: due retries, deliveries whose job was lost, and deliveries stuck mid-send.
        WebhookDelivery::acrossTenants()->where('status', 'delivering')->where('last_attempt_at', '<', $stale)
            ->update(['status' => 'failed', 'next_attempt_at' => now(), 'error' => 'The delivery was interrupted.', 'updated_at' => now()]);
        $deliveries = WebhookDelivery::acrossTenants()
            ->where(fn ($q) => $q->where(fn ($w) => $w->where('status', 'failed')->where('next_attempt_at', '<=', now()))
                ->orWhere(fn ($w) => $w->where('status', 'pending')->where('updated_at', '<=', now()->subMinutes(2))))
            // Paused subscriptions keep their pending deliveries until resumed.
            ->whereIn('subscription_id', fn ($q) => $q->select('id')->from('webhook_subscriptions')->where('status', 'active'))
            ->orderBy('id')->limit((int) $this->option('limit'))->pluck('id');
        $deliveries->each(fn ($id) => DeliverWebhook::dispatch($id));

        $this->info("Queued {$due->count()} message(s) and {$deliveries->count()} webhook deliveries; released {$released} stuck message(s).");

        return self::SUCCESS;
    }
}

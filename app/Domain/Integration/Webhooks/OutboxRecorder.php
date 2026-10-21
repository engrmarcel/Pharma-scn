<?php

namespace App\Domain\Integration\Webhooks;

use App\Domain\Integration\Models\OutboxEvent;
use App\Domain\Integration\Models\WebhookDelivery;
use App\Domain\Integration\Models\WebhookSubscription;
use App\Support\Events\DomainEvent;
use App\Support\Events\EventRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The transactional outbox. Called by DomainEvents::raise() inside the
 * business transaction: when the organization has active subscriptions for
 * the event type, the event and one pending delivery per subscription are
 * written in that same transaction. Delivery jobs are queued only after it
 * commits. Organizations without subscribers pay nothing.
 */
class OutboxRecorder implements EventRecorder
{
    public function record(DomainEvent $event): void
    {
        $type = OutboundEvents::typeFor($event);
        $organizationId = property_exists($event, 'organizationId') ? $event->organizationId : null;
        if ($type === null || $organizationId === null) {
            return;
        }

        $subscriptions = WebhookSubscription::acrossTenants()->where('organization_id', $organizationId)->where('status', 'active')->get()
            ->filter(fn (WebhookSubscription $s) => $s->wants($type));
        if ($subscriptions->isEmpty()) {
            return;
        }

        [$aggregateType, $aggregateId, $payload] = OutboundEvents::payload($event);
        $this->write($organizationId, $type, $aggregateType, $aggregateId, $payload, $event->occurredAt, $event->correlationId, $subscriptions->all());
    }

    /**
     * @param  list<WebhookSubscription>  $subscriptions
     * @return list<int> delivery ids
     */
    public function write(string $organizationId, string $type, ?string $aggregateType, ?string $aggregateId, array $payload, ?string $occurredAt, ?string $correlationId, array $subscriptions): array
    {
        return DB::transaction(function () use ($organizationId, $type, $aggregateType, $aggregateId, $payload, $occurredAt, $correlationId, $subscriptions) {
            $outbox = OutboxEvent::query()->create([
                'organization_id' => $organizationId,
                'event_id' => (string) Str::ulid(),
                'event_type' => $type,
                'aggregate_type' => $aggregateType,
                'aggregate_id' => $aggregateId,
                'payload' => $payload,
                'occurred_at' => $occurredAt ? now()->parse($occurredAt) : now(),
                'correlation_id' => $correlationId,
            ]);

            $ids = [];
            foreach ($subscriptions as $subscription) {
                $ids[] = WebhookDelivery::query()->create(['organization_id' => $organizationId, 'subscription_id' => $subscription->id, 'outbox_id' => $outbox->id])->id;
            }

            DB::afterCommit(function () use ($ids) {
                foreach ($ids as $id) {
                    DeliverWebhook::dispatch($id);
                }
            });

            return $ids;
        });
    }
}

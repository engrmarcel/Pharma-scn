<?php

namespace Tests\Feature;

use App\Domain\Integration\Models\IntegrationMessage;
use App\Domain\Integration\Models\OutboxEvent;
use App\Domain\Integration\Models\WebhookDelivery;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithIntegrations;
use Tests\FeatureTestCase;

/**
 * Phase 10c failure injection: work interrupted by a lost queue job or a crashed worker is
 * recovered by the per-minute sweeper (integration:process-due), not lost.
 */
class ResilienceTest extends FeatureTestCase
{
    use InteractsWithIntegrations;

    private const HOOK = 'https://erp.example.test/hooks/resilience';

    protected function setUp(): void
    {
        parent::setUp();
        // The test host does not resolve (address checks have their own test in IntegrationWebhooksTest).
        config(['integrations.webhooks.allow_private_networks' => true]);
    }

    public function test_a_webhook_whose_job_was_lost_and_one_interrupted_mid_send_are_both_delivered_later(): void
    {
        [$admin, $org] = $this->integrationOrg();
        Http::fake([self::HOOK => Http::response('', 204)]);
        $subscriptionId = $this->actingAsApi($admin, $org)->api('POST', 'integration/webhooks', ['name' => 'ERP', 'url' => self::HOOK, 'event_types' => ['*']])->json('data.id');

        // The event committed, but the queue lost the job that should have sent it (for example Redis restarted).
        $event = fn () => OutboxEvent::query()->withoutGlobalScopes()->create(['organization_id' => $org->id, 'event_id' => (string) Str::ulid(), 'event_type' => 'webhook.test',
            'payload' => ['resilience' => true], 'occurred_at' => now()]);
        $lost = WebhookDelivery::query()->withoutGlobalScopes()->create(['organization_id' => $org->id, 'subscription_id' => $subscriptionId, 'outbox_id' => $event()->id]);
        // And a worker died while sending another one.
        $interrupted = WebhookDelivery::query()->withoutGlobalScopes()->create(['organization_id' => $org->id, 'subscription_id' => $subscriptionId, 'outbox_id' => $event()->id]);
        $interrupted->forceFill(['status' => 'delivering', 'attempts' => 1, 'last_attempt_at' => now()])->save();

        $this->artisan('integration:process-due')->assertSuccessful();
        $this->assertSame('pending', $lost->fresh()->status, 'a fresh delivery is left to its own job for two minutes');

        $this->travel(config('integrations.stale_processing_minutes') + 1)->minutes();
        $this->artisan('integration:process-due')->assertSuccessful();
        $this->assertSame('delivered', $lost->fresh()->status, (string) $lost->fresh()->error);
        $this->assertSame(['delivered', 2], [$interrupted->fresh()->status, $interrupted->fresh()->attempts], (string) $interrupted->fresh()->error);
        Http::assertSentCount(2);
    }

    public function test_an_integration_message_left_processing_by_a_crashed_worker_is_released_and_processed(): void
    {
        [$admin, $org] = $this->integrationOrg();
        $this->sampleProducts();
        [$connectorId, $key] = $this->activeConnector($admin, $org);
        $this->sapMappings($connectorId);

        // Accepted, but the worker that picked it up died before finishing.
        $realQueue = Queue::getFacadeRoot();
        Queue::fake();
        $this->inbound($key, [$this->envelopes('materials', 'product', 'CRASH')[0]])->assertJsonPath('data.0.status', 'accepted');
        $message = IntegrationMessage::acrossTenants()->where('message_id', 'CRASH-0')->firstOrFail();
        $message->forceFill(['status' => 'processing', 'processing_started_at' => now()])->save();
        Queue::swap($realQueue);

        // One sweep releases it; the next (a minute later, as for any message waiting) processes it.
        $this->travel(config('integrations.stale_processing_minutes') + 1)->minutes();
        $this->artisan('integration:process-due')->assertSuccessful();
        $this->assertSame('received', $message->fresh()->status, 'released');
        $this->travel(2)->minutes();
        $this->artisan('integration:process-due')->assertSuccessful();
        $this->assertSame('processed', $message->fresh()->status, 'queued again and processed');
    }
}

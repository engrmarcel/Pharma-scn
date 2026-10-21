<?php

namespace App\Domain\Integration\Webhooks;

use App\Domain\Integration\Models\WebhookDelivery;
use App\Domain\Integration\Models\WebhookSubscription;
use App\Support\Exceptions\BusinessRuleViolation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Delivers one outbox event to one subscriber (at least once).
 *
 * Request: POST JSON envelope {id, type, occurred_at, organization_id, data} with
 *   X-Webhook-Id (event id: the receiver's idempotency key), X-Webhook-Event,
 *   X-Webhook-Delivery, X-Webhook-Attempt, X-Webhook-Timestamp and
 *   X-Webhook-Signature: sha256=HMAC-SHA256(secret, "<timestamp>.<body>").
 * A 2xx response is success. Redirects are not followed. Anything else is
 * retried with backoff; after max_attempts the delivery is dead and waits for
 * a manual redelivery. A subscription that keeps failing is paused.
 */
class WebhookDispatcher
{
    public function deliver(int $deliveryId): void
    {
        $claimed = WebhookDelivery::acrossTenants()->whereKey($deliveryId)->whereIn('status', ['pending', 'failed'])
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->update(['status' => 'delivering', 'attempts' => DB::raw('attempts + 1'), 'last_attempt_at' => now(), 'updated_at' => now()]);
        if ($claimed !== 1) {
            return;
        }

        // Runs from the queue without a tenant context: load across tenants, by id.
        $delivery = WebhookDelivery::acrossTenants()
            ->with(['outbox' => fn ($q) => $q->withoutGlobalScopes(), 'subscription' => fn ($q) => $q->withoutGlobalScopes()])
            ->findOrFail($deliveryId);
        $subscription = $delivery->subscription;

        if ($subscription->status === 'disabled') {
            $this->finish($delivery, 'dead', error: 'The subscription is disabled.');

            return;
        }
        if ($subscription->status !== 'active') {
            // Paused: keep it pending; it is sent when the subscription is resumed.
            $delivery->forceFill(['status' => 'pending', 'attempts' => max(0, $delivery->attempts - 1)])->save();

            return;
        }

        $body = json_encode($this->envelope($delivery, $subscription), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $timestamp = (string) time();
        $started = hrtime(true);

        try {
            $pin = UrlGuard::pin($subscription->url);
            $response = Http::timeout(config('integrations.webhooks.timeout_seconds'))->connectTimeout(5)->withoutRedirecting()
                ->withOptions($pin ? ['curl' => [CURLOPT_RESOLVE => [$pin]]] : [])
                ->withHeaders([
                    'User-Agent' => config('integrations.webhooks.user_agent'),
                    'X-Webhook-Id' => $delivery->outbox->event_id,
                    'X-Webhook-Event' => $delivery->outbox->event_type,
                    'X-Webhook-Delivery' => (string) $delivery->id,
                    'X-Webhook-Attempt' => (string) $delivery->attempts,
                    'X-Webhook-Timestamp' => $timestamp,
                    'X-Webhook-Signature' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $subscription->secret),
                ])
                ->withBody($body, 'application/json')
                ->post($subscription->url);
        } catch (BusinessRuleViolation $e) {
            $this->failed($delivery, $subscription, null, null, $e->getMessage(), permanent: $e->errorCode === 'webhook_url_blocked');

            return;
        } catch (ConnectionException) {
            $this->failed($delivery, $subscription, null, null, 'Connection failed or timed out.');

            return;
        } catch (Throwable $e) {
            report($e);
            $this->failed($delivery, $subscription, null, null, 'Unexpected error while sending.');

            return;
        }

        $duration = (int) ((hrtime(true) - $started) / 1_000_000);
        if ($response->successful()) {
            $this->finish($delivery, 'delivered', $response->status(), $duration);
            $subscription->forceFill(['consecutive_failures' => 0, 'last_delivered_at' => now()])->saveQuietly();

            return;
        }

        $this->failed($delivery, $subscription, $response->status(), mb_substr(trim($response->body()), 0, 300), "HTTP {$response->status()}", duration: $duration);
    }

    /** The event, plus the subscriber's connector keys next to our product ids. */
    private function envelope(WebhookDelivery $delivery, WebhookSubscription $subscription): array
    {
        $envelope = $delivery->outbox->envelope();
        if ($subscription->connector_id !== null) {
            $keys = DB::table('integration_external_references')->where('connector_id', $subscription->connector_id)->where('entity_type', 'product')
                ->orderBy('id')->get(['internal_id', 'external_id'])->unique('internal_id')->pluck('external_id', 'internal_id')->all();
            $envelope['data'] = $this->withExternalKeys($envelope['data'], $keys);
        }

        return $envelope;
    }

    private function withExternalKeys(array $data, array $keys): array
    {
        if (isset($data['product_id']) && is_string($data['product_id'])) {
            $data['product_external_id'] = $keys[$data['product_id']] ?? null;
        }
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->withExternalKeys($value, $keys);
            }
        }

        return $data;
    }

    private function failed(WebhookDelivery $delivery, WebhookSubscription $subscription, ?int $status, ?string $excerpt, string $error, bool $permanent = false, ?int $duration = null): void
    {
        $backoff = config('integrations.webhooks.retry_backoff_minutes');
        $dead = $permanent || $delivery->attempts >= config('integrations.webhooks.max_attempts');
        $delivery->forceFill([
            'status' => $dead ? 'dead' : 'failed',
            'next_attempt_at' => $dead ? null : now()->addMinutes($backoff[min($delivery->attempts - 1, count($backoff) - 1)]),
            'response_status' => $status,
            'response_excerpt' => $excerpt,
            'error' => mb_strimwidth($error, 0, 255),
            'duration_ms' => $duration,
        ])->save();

        $failures = $subscription->consecutive_failures + 1;
        $pause = $failures >= config('integrations.webhooks.pause_after_consecutive_failures');
        $subscription->forceFill(['consecutive_failures' => $failures, 'last_failure_at' => now()]
            + ($pause ? ['status' => 'paused', 'status_reason' => "Paused automatically after {$failures} consecutive failed attempts."] : []))->saveQuietly();
    }

    private function finish(WebhookDelivery $delivery, string $status, ?int $responseStatus = null, ?int $duration = null, ?string $error = null): void
    {
        $delivery->forceFill(['status' => $status, 'response_status' => $responseStatus, 'duration_ms' => $duration, 'error' => $error, 'next_attempt_at' => null,
            'response_excerpt' => null, 'delivered_at' => $status === 'delivered' ? now() : null])->save();
    }
}

<?php

namespace Tests\Feature;

use App\Domain\Catalogue\Models\OrganizationProduct;
use App\Domain\Integration\Models\OutboxEvent;
use App\Domain\Integration\Models\WebhookDelivery;
use App\Domain\Integration\Models\WebhookSubscription;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Concerns\InteractsWithIntegrations;
use Tests\FeatureTestCase;

/**
 * Phase 5c: the integration outbox and webhooks. Events are written in the
 * same transaction as the business change, delivered signed and at least
 * once, retried with backoff, dead-lettered and redeliverable; subscriber URLs
 * cannot reach internal networks.
 */
class IntegrationWebhooksTest extends FeatureTestCase
{
    use InteractsWithIntegrations;

    private const HOOK = 'https://erp.example.test/hooks/pharma';

    protected function setUp(): void
    {
        parent::setUp();
        // The test host does not resolve; the address checks are covered in their own test.
        config(['integrations.webhooks.allow_private_networks' => true]);
    }

    public function test_events_are_recorded_with_the_business_change_and_delivered_signed_with_the_erps_own_keys(): void
    {
        [$admin, $org] = $this->integrationOrg();
        [$amox] = $this->sampleProducts();
        $main = $this->warehouse($org);
        [$connectorId, $key] = $this->activeConnector($admin, $org);
        // The ERP's material number for Amoxicillin becomes known through an inbound product record.
        $this->inbound($key, [['message_id' => 'P-1', 'entity_type' => 'product', 'record' => ['external_id' => 'MAT-100234', 'name' => 'Amox', 'gtin' => '6901234000016']]]);
        Http::fake([self::HOOK => Http::response(['received' => true])]);

        $created = $this->api('POST', 'integration/webhooks', ['name' => 'S/4 stock feed', 'url' => self::HOOK,
            'event_types' => ['inventory.stock_received', 'inventory.batch_created'], 'connector_id' => $connectorId])->assertCreated();
        $secret = $created->json('data.secret');
        $this->assertSame(48, strlen($secret));
        $this->api('GET', 'integration/webhooks/'.$created->json('data.id'))->assertOk()->assertJsonMissingPath('data.secret');

        $this->receive($org, $main, $amox, 'WH-LOT-1', '240', '+2 years');

        $events = OutboxEvent::acrossTenants()->orderBy('id')->get();
        $this->assertSame(['inventory.batch_created', 'inventory.stock_received'], $events->pluck('event_type')->all());
        $this->assertSame(['delivered', 'delivered'], WebhookDelivery::acrossTenants()->orderBy('id')->pluck('status')->all());

        Http::assertSentCount(2);
        Http::assertSent(function (Request $request) use ($secret, $events) {
            if ($request->header('X-Webhook-Event')[0] !== 'inventory.stock_received') {
                return false;
            }
            $body = $request->body();
            $expected = 'sha256='.hash_hmac('sha256', $request->header('X-Webhook-Timestamp')[0].'.'.$body, $secret);
            $movement = json_decode($body, true)['data']['movements'][0];

            return $request->header('X-Webhook-Signature')[0] === $expected
                && $request->header('X-Webhook-Id')[0] === $events[1]->event_id
                && [$movement['warehouse_code'], $movement['batch_number'], $movement['quantity'], $movement['stock_state'], $movement['gtin'], $movement['product_external_id']]
                    === ['MAIN', 'WH-LOT-1', '240.0000', 'available', '6901234000016', 'MAT-100234'];
        });

        // Event types nobody subscribed to are not recorded; nor is anything for organizations without subscribers.
        [$adminB, $orgB] = $this->integrationOrg();
        $this->inTenant($orgB, fn () => OrganizationProduct::query()->create(['product_id' => $amox->id, 'sku' => 'B-AMX', 'currency_code' => 'NGN']));
        $this->receive($orgB, $this->warehouse($orgB), $amox, 'OTHER-1', '10', '+1 year');
        $this->assertSame(2, OutboxEvent::acrossTenants()->count());
        $this->actingAsApi($adminB, $orgB)->api('GET', 'integration/webhooks/'.$created->json('data.id'))->assertNotFound();
        $this->assertSame([], $this->api('GET', 'integration/outbox')->json('data'));
    }

    public function test_a_rolled_back_change_leaves_no_event_behind(): void
    {
        [$admin, $org] = $this->integrationOrg();
        [$amox] = $this->sampleProducts();
        $main = $this->warehouse($org);
        $this->inTenant($org, fn () => OrganizationProduct::query()->create(['product_id' => $amox->id, 'sku' => 'AMX', 'currency_code' => 'NGN']));
        Http::fake();
        $this->actingAsApi($admin, $org)->api('POST', 'integration/webhooks', ['name' => 'All', 'url' => self::HOOK, 'event_types' => ['*']])->assertCreated();

        try {
            DB::transaction(function () use ($org, $main, $amox) {
                $this->receive($org, $main, $amox, 'ROLLBACK-1', '5', '+1 year');
                throw new RuntimeException('the surrounding business operation failed');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(0, OutboxEvent::acrossTenants()->count());
        $this->assertSame(0, WebhookDelivery::acrossTenants()->count());
        Http::assertNothingSent();
    }

    public function test_failed_deliveries_back_off_go_dead_and_can_be_redelivered_and_failing_endpoints_are_paused(): void
    {
        [$admin, $org] = $this->integrationOrg();
        $id = $this->actingAsApi($admin, $org)->api('POST', 'integration/webhooks', ['name' => 'Flaky', 'url' => self::HOOK, 'event_types' => ['webhook.test']])->json('data.id');
        // One fake for the whole test (later Http::fake calls would only add stubs behind the first).
        $reply = [500, 'upstream exploded'];
        Http::fake([self::HOOK => function () use (&$reply) {
            return Http::response($reply[1], $reply[0]);
        }]);

        $this->api('POST', "integration/webhooks/{$id}/test")->assertStatus(202);
        $delivery = WebhookDelivery::acrossTenants()->firstOrFail();
        $this->assertSame(['failed', 1, 500, 'upstream exploded'], [$delivery->status, $delivery->attempts, $delivery->response_status, $delivery->response_excerpt]);
        $this->assertTrue($delivery->next_attempt_at->isFuture());

        for ($i = 0; $i < 10; $i++) {
            $this->travel(13)->hours();
            $this->artisan('integration:process-due')->assertSuccessful();
        }
        $delivery->refresh();
        $this->assertSame(['dead', 8], [$delivery->status, $delivery->attempts]);
        $this->assertSame(8, WebhookSubscription::acrossTenants()->find($id)->consecutive_failures);

        // Receiver fixed: a manual redelivery succeeds and resets the failure streak.
        $reply = [204, ''];
        $this->api('POST', "integration/webhook-deliveries/{$delivery->id}/redeliver")->assertOk();
        $this->assertSame('delivered', $delivery->fresh()->status);
        $this->assertSame(0, WebhookSubscription::acrossTenants()->find($id)->consecutive_failures);
        $this->api('POST', "integration/webhook-deliveries/{$delivery->id}/redeliver")->assertStatus(409);

        // A receiver that keeps failing is paused; its events wait and go out once it is resumed.
        config(['integrations.webhooks.pause_after_consecutive_failures' => 2]);
        $reply = [503, ''];
        $this->api('POST', "integration/webhooks/{$id}/test");
        $this->travel(2)->minutes();
        $this->artisan('integration:process-due');
        $subscription = WebhookSubscription::acrossTenants()->find($id);
        $this->assertSame('paused', $subscription->status);
        $this->assertStringContainsString('Paused automatically', $subscription->status_reason);

        $reply = [200, ''];
        $this->api('POST', "integration/webhooks/{$id}/test")->assertStatus(409);
        $this->api('POST', "integration/webhooks/{$id}/activate")->assertOk();
        $this->travel(2)->hours();
        $this->artisan('integration:process-due');
        $this->assertSame(0, WebhookDelivery::acrossTenants()->whereIn('status', ['pending', 'failed'])->count());
        $this->api('GET', "integration/webhooks/{$id}/deliveries?status=delivered")->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_webhook_urls_cannot_reach_internal_networks(): void
    {
        [$admin, $org] = $this->integrationOrg();
        config(['integrations.webhooks.allow_private_networks' => false]);
        $this->actingAsApi($admin, $org);

        foreach (['http://erp.example.test/hook' => 'webhook_url_invalid', 'https://user:pw@erp.example.test/hook' => 'webhook_url_invalid',
            'https://127.0.0.1/hook' => 'webhook_url_blocked', 'https://10.20.30.40/hook' => 'webhook_url_blocked', 'https://169.254.169.254/latest/meta-data' => 'webhook_url_blocked',
            'https://[::1]/hook' => 'webhook_url_blocked', 'https://localhost/hook' => 'webhook_url_blocked', 'https://db.internal/hook' => 'webhook_url_blocked'] as $url => $code) {
            $this->api('POST', 'integration/webhooks', ['name' => 'x', 'url' => $url, 'event_types' => ['webhook.test']])->assertStatus(422)->assertJsonPath('error.code', $code);
        }
        $this->api('POST', 'integration/webhooks', ['name' => 'x', 'url' => 'https://8.8.8.8/hook', 'event_types' => ['warp.drive']])->assertStatus(422)->assertJsonPath('error.code', 'webhook_event_type_unknown');

        // Checked again at delivery: a URL that became internal is dead at once, without retries or a request.
        Http::fake();
        $id = $this->api('POST', 'integration/webhooks', ['name' => 'Public', 'url' => 'https://8.8.8.8/hook', 'event_types' => ['webhook.test']])->assertCreated()->json('data.id');
        WebhookSubscription::acrossTenants()->whereKey($id)->update(['url' => 'https://192.168.1.10/hook']);
        $this->api('POST', "integration/webhooks/{$id}/test")->assertStatus(202)->assertJsonPath('data.status', 'dead');
        Http::assertNothingSent();
    }

    public function test_secret_rotation_reference_reconciliation_and_platform_visibility(): void
    {
        [$admin, $org] = $this->integrationOrg();
        [$amox, $pcm] = $this->sampleProducts();
        $main = $this->warehouse($org);
        $this->warehouse($org, 'COLD');
        [$connectorId, $key] = $this->activeConnector($admin, $org);
        $this->inbound($key, [['message_id' => 'P-1', 'entity_type' => 'product', 'record' => ['external_id' => 'MAT-1', 'name' => 'Amox', 'gtin' => '6901234000016']]]);
        $this->inTenant($org, fn () => OrganizationProduct::query()->create(['product_id' => $pcm->id, 'sku' => 'PCM', 'currency_code' => 'NGN']));
        $this->actingAsApi($admin, $org)->api('POST', 'integration/external-references', ['connector_id' => $connectorId, 'entity_type' => 'warehouse', 'external_id' => '1000-0001', 'internal_id' => $main->id])->assertCreated();
        $this->inbound($key, [['message_id' => 'P-2', 'entity_type' => 'product', 'record' => ['external_id' => 'MAT-2', 'name' => 'Unknown']]]);

        $report = $this->api('GET', "integration/connectors/{$connectorId}/reconciliation/references")->assertOk()->json('data');
        $this->assertSame([2, 1], [$report['products']['catalogue_items'], $report['products']['mapped']]);
        $this->assertSame('PCM', $report['products']['unmapped_catalogue_items'][0]['sku']);
        $this->assertSame(['COLD' => false, 'MAIN' => true], collect($report['warehouses'])->pluck('mapped', 'code')->all());
        $this->assertSame(['product_unmatched' => 1], $report['open_quarantine_by_code']);

        // Rotated secret: the next delivery is signed with the new one.
        Http::fake();
        $id = $this->api('POST', 'integration/webhooks', ['name' => 'Test', 'url' => self::HOOK, 'event_types' => ['webhook.test']])->json('data.id');
        $newSecret = $this->api('POST', "integration/webhooks/{$id}/secret")->assertCreated()->json('data.secret');
        $this->api('POST', "integration/webhooks/{$id}/test")->assertJsonPath('data.status', 'delivered');
        Http::assertSent(fn (Request $r) => $r->header('X-Webhook-Signature')[0] === 'sha256='.hash_hmac('sha256', $r->header('X-Webhook-Timestamp')[0].'.'.$r->body(), $newSecret));
        $this->assertStringNotContainsString($newSecret, json_encode(DB::table('webhook_subscriptions')->get()));

        $types = collect($this->api('GET', 'integration/webhooks/event-types')->json('data'))->pluck('type');
        $this->assertTrue($types->contains('inventory.stock_received') && $types->contains('sales.invoice_issued'));

        // Platform operations see counts across organizations, never payloads.
        $ops = $this->platformUser('platform_operations_admin');
        $status = $this->actingAsApi($ops)->api('GET', 'admin/operations/status')->assertOk()->json('data.integrations');
        $this->assertSame(['active_connectors' => 1, 'inbox_quarantined' => 1], array_intersect_key($status, ['active_connectors' => 0, 'inbox_quarantined' => 0]));
    }
}

<?php

namespace Tests\Feature;

use App\Domain\Catalogue\Models\OrganizationProduct;
use App\Domain\Integration\Handlers\IntegrationHandler;
use App\Domain\Integration\Handlers\ProductHandler;
use App\Domain\Integration\Models\ExternalReference;
use App\Domain\Integration\Models\IntegrationConnector;
use App\Domain\Integration\Models\IntegrationMessage;
use App\Domain\Inventory\Models\Batch;
use App\Domain\Platform\Models\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;
use Tests\Concerns\InteractsWithIntegrations;
use Tests\FeatureTestCase;

/**
 * Phase 5a acceptance (spec §15, §31 Phase 5):
 *   a sample external ERP dataset maps into the canonical model;
 *   invalid data is rejected or quarantined with an actionable error;
 *   retried messages do not duplicate business transactions;
 *   integration health and failures are visible;
 *   credentials stay protected;
 *   the organization keeps its ERP (records flow in by connector; nothing is replaced).
 */
class IntegrationGatewayTest extends FeatureTestCase
{
    use InteractsWithIntegrations;

    public function test_sample_erp_dataset_is_mapped_into_the_canonical_model_and_applied(): void
    {
        [$admin, $org] = $this->integrationOrg();
        [$amox, $pcm] = $this->sampleProducts();
        $main = $this->warehouse($org);
        [$connectorId, $key] = $this->activeConnector($admin, $org);
        $this->sapMappings($connectorId);

        // Preview: mapping and validation without side effects.
        $preview = $this->api('POST', "integration/connectors/{$connectorId}/mappings/batch/preview", ['records' => $this->sampleDataset()['batches']])->assertOk()->json('data');
        $this->assertSame(['product_external_id' => '100234', 'batch_number' => 'AMX2026A', 'expiry_date' => '2028-12-31', 'manufacturing_date' => '2026-01-15', 'quality_status' => 'released'], $preview[0]['canonical']);
        $this->assertTrue($preview[0]['valid']);
        $this->assertArrayNotHasKey('manufacturing_date', $preview[1]['canonical'], 'SAP 00000000 means no date');
        $this->assertFalse($preview[2]['valid']);
        $this->assertSame('mapping_failed', $preview[2]['errors'][0]['code']);
        $this->assertSame(0, IntegrationMessage::acrossTenants()->count());

        // Products: accepted (stored) first; the business outcome is read back per message.
        $response = $this->inbound($key, $this->envelopes('materials', 'product', 'MAT'))->assertStatus(202);
        $this->assertSame(['accepted', 'accepted', 'accepted'], array_column($response->json('data'), 'status'));
        $this->assertSame(3, $response->json('run.counts.received'));

        $first = $this->messageStatus($key, 'MAT-0')->assertOk()->json('data');
        $this->assertSame('processed', $first['status']);
        $this->assertEquals(['action' => 'created', 'matched_by' => 'gtin', 'product_id' => $amox->id, 'sku' => '100234'],
            array_intersect_key($first['result'], array_flip(['action', 'matched_by', 'product_id', 'sku'])));
        $this->assertSame('registration_number', $this->messageStatus($key, 'MAT-1')->json('data.result.matched_by'));

        $item = $this->inTenant($org, fn () => OrganizationProduct::query()->where('product_id', $amox->id)->first());
        $this->assertSame(['100234', 'AMOXICILLIN 500MG CAPS 2X10', '1250.5000', 'NGN'], [$item->sku, $item->local_name, (string) $item->default_purchase_cost, $item->currency_code]);

        // The unknown material is quarantined with an actionable error, never guessed.
        $unmatched = $this->messageStatus($key, 'MAT-2')->json('data');
        $this->assertSame('quarantined', $unmatched['status']);
        $this->assertSame('product_unmatched', $unmatched['error']['code']);
        $this->assertStringContainsString('/integration/messages/{id}/map', $unmatched['error']['message']);
        $this->assertSame('100999', $unmatched['error']['details']['external_id']);

        // Batches: created with expiry and quality; an impossible date is quarantined naming the source field.
        $this->inbound($key, $this->envelopes('batches', 'batch', 'BAT'))->assertStatus(202);
        $this->assertSame('created', $this->messageStatus($key, 'BAT-0')->json('data.result.action'));
        $batches = $this->inTenant($org, fn () => Batch::query()->orderBy('batch_number')->get()->keyBy('batch_number'));
        $this->assertSame(['2028-12-31', 'released'], [$batches['AMX2026A']->expiry_date->toDateString(), $batches['AMX2026A']->quality_status]);
        $this->assertSame('quarantined', $batches['PCM-77']->quality_status);
        $bad = $this->messageStatus($key, 'BAT-2')->json('data');
        $this->assertSame(['quarantined', 'mapping_failed', 'VFDAT'], [$bad['status'], $bad['error']['code'], $bad['error']['details']['source_path']]);
        $this->assertFalse($batches->has('AMX2026B'));

        // Stock: the SAP location is not a platform warehouse code; it is quarantined until mapped.
        $this->receive($org, $main, $amox, 'AMX2026A', '1150', '2028-12-31');
        $this->receive($org, $main, $amox, 'AMX-OLD', '40', '+1 year');
        $this->inbound($key, $this->envelopes('stock', 'inventory_snapshot', 'STK'))->assertStatus(202);
        $stock = $this->messageStatus($key, 'STK-0')->json('data');
        $this->assertSame('warehouse_unmatched', $stock['error']['code']);
        $this->assertSame(['MAIN'], $stock['error']['details']['warehouses']);

        $messageId = IntegrationMessage::acrossTenants()->where('message_id', 'STK-0')->value('id');
        $this->api('POST', "integration/messages/{$messageId}/map", ['warehouse_id' => $main->id])->assertOk()->assertJsonPath('data.status', 'processed');
        $other = IntegrationMessage::acrossTenants()->where('message_id', 'STK-1')->value('id');
        $this->api('POST', "integration/messages/{$other}/retry")->assertOk()->assertJsonPath('data.status', 'processed');
        $this->assertSame('600.0000', $this->messageStatus($key, 'STK-1')->json('data.result.base_quantity'), '30 packs of 20 capsules');

        // Reconciliation: source stock against platform stock; snapshots never moved platform stock.
        $report = $this->api('GET', "integration/connectors/{$connectorId}/reconciliation/inventory")->assertOk()->json('data');
        $rows = collect($report['rows'])->keyBy('batch_number');
        $this->assertSame(['1200.0000', '1150.0000', '-50.0000', 'mismatch'], [$rows['AMX2026A']['source_quantity'], $rows['AMX2026A']['platform_quantity'], $rows['AMX2026A']['difference'], $rows['AMX2026A']['status']]);
        $this->assertSame('not_on_platform', $rows['PCM-77']['status']);
        $this->assertSame('not_reported_by_source', $rows['AMX-OLD']['status']);
        $this->assertSame(['1150.0000', '0.0000'], $this->balance($org, $main, $batches['AMX2026A']));

        // Health shows the run: quarantined messages need attention.
        $health = collect($this->api('GET', 'integration/health')->assertOk()->json('data'))->firstWhere('connector_id', $connectorId);
        $this->assertSame('needs_attention', $health['health']);
        $this->assertSame(['received' => 8, 'pending' => 0, 'processed' => 6, 'quarantined' => 2, 'failed' => 0, 'ignored' => 0], $health['totals']);
    }

    public function test_unmatched_product_is_mapped_by_a_person_and_later_records_resolve_through_the_reference(): void
    {
        [$admin, $org] = $this->integrationOrg();
        $this->sampleProducts();
        $zinc = $this->makeProduct(['generic_name' => 'Zinc sulphate', 'brand_name' => 'Zincora', 'dosage_form_code' => 'tablet',
            'ingredients' => [['name' => 'Zinc sulphate', 'strength_value' => 20, 'strength_unit' => 'mg']]]);
        [$connectorId, $key] = $this->activeConnector($admin, $org);
        $this->sapMappings($connectorId);

        $this->inbound($key, [$this->envelopes('materials', 'product', 'MAT')[2]])->assertStatus(202);
        $id = IntegrationMessage::acrossTenants()->where('message_id', 'MAT-2')->value('id');
        $this->api('GET', "integration/messages/{$id}")->assertOk()->assertJsonPath('data.error.code', 'product_unmatched')
            ->assertJsonPath('data.canonical.external_id', '100999');

        $this->api('POST', "integration/messages/{$id}/map", ['product_id' => $zinc->id])->assertOk()
            ->assertJsonPath('data.status', 'processed')->assertJsonPath('data.result.matched_by', 'reference');
        $this->assertSame('manual', ExternalReference::acrossTenants()->where('external_id', '100999')->value('source'));

        $this->inbound($key, [['message_id' => 'BAT-Z', 'entity_type' => 'batch', 'record' => ['MATNR' => '000000000000100999', 'CHARG' => 'zn-1', 'VFDAT' => '20290101', 'HSDAT' => '', 'ZUSTD' => 'F']]]);
        $this->assertSame('processed', $this->messageStatus($key, 'BAT-Z')->json('data.status'));
        $this->assertSame($zinc->id, $this->inTenant($org, fn () => Batch::query()->where('batch_number', 'ZN-1')->value('product_id')));

        // Audit trail of the manual decision.
        $this->assertTrue(AuditLog::query()->where('organization_id', $org->id)->where('action', 'integration.reference.mapped')->exists());
    }

    public function test_replays_and_retries_never_duplicate_business_records(): void
    {
        [$admin, $org] = $this->integrationOrg();
        $this->sampleProducts();
        [$connectorId, $key] = $this->activeConnector($admin, $org);
        $this->sapMappings($connectorId);
        $this->inbound($key, $this->envelopes('materials', 'product', 'MAT'));
        $batch = $this->envelopes('batches', 'batch', 'BAT')[0];
        $this->inbound($key, [$batch])->assertJsonPath('data.0.status', 'accepted');

        // Same message again (a lost acknowledgement): the stored outcome, not a second batch.
        $replay = $this->inbound($key, [$batch])->assertStatus(202);
        $this->assertSame(['processed', true], [$replay->json('data.0.status'), $replay->json('data.0.duplicate')]);
        $this->assertSame(1, $replay->json('run.counts.duplicates'));
        // Key order does not make it a different record.
        $this->inbound($key, [['message_id' => 'BAT-0', 'entity_type' => 'batch', 'record' => array_reverse($batch['record'], true)]])->assertJsonPath('data.0.duplicate', true);

        // Changed data under a used message_id is refused, not silently applied.
        $changed = $batch;
        $changed['record']['VFDAT'] = '20291231';
        $this->inbound($key, [$changed])->assertJsonPath('data.0.status', 'rejected')->assertJsonPath('data.0.error.code', 'message_id_reused');

        // The same record under a new message_id is applied idempotently.
        $this->inbound($key, [['message_id' => 'BAT-0-RESENT'] + $batch]);
        $this->assertSame('unchanged', $this->messageStatus($key, 'BAT-0-RESENT')->json('data.result.action'));
        $this->assertSame(1, $this->inTenant($org, fn () => Batch::query()->where('batch_number', 'AMX2026A')->count()));
        $this->assertSame(2, IntegrationMessage::acrossTenants()->where('entity_type', 'batch')->count());

        // A processed message cannot be retried; a contradicting expiry is quarantined, never merged.
        $processed = IntegrationMessage::acrossTenants()->where('message_id', 'BAT-0')->value('id');
        $this->actingAsApi($admin, $org)->api('POST', "integration/messages/{$processed}/retry")->assertStatus(409);
        $this->inbound($key, [['message_id' => 'BAT-0-V2'] + $changed]);
        $this->assertSame('batch_expiry_mismatch', $this->messageStatus($key, 'BAT-0-V2')->json('data.error.code'));
        $this->assertSame('2028-12-31', $this->inTenant($org, fn () => Batch::query()->where('batch_number', 'AMX2026A')->first()->expiry_date->toDateString()));

        // Ignoring keeps the message, with who and why.
        $v2 = IntegrationMessage::acrossTenants()->where('message_id', 'BAT-0-V2')->value('id');
        $this->api('POST', "integration/messages/{$v2}/ignore", ['reason' => 'Typo in SAP corrected at source'])->assertOk()
            ->assertJsonPath('data.status', 'ignored')->assertJsonPath('data.resolution.notes', 'Typo in SAP corrected at source');
    }

    public function test_conflicting_identifiers_and_invalid_records_are_quarantined_or_rejected(): void
    {
        [$admin, $org] = $this->integrationOrg();
        [$amox] = $this->sampleProducts();
        [, $key] = $this->activeConnector($admin, $org);

        // No mappings: records are sent in canonical form.
        $response = $this->inbound($key, [
            ['message_id' => 'C-1', 'entity_type' => 'product', 'record' => ['external_id' => 'X1', 'name' => 'Mixed up', 'gtin' => '6901234000016', 'registration_number' => 'A4-0457']],
            ['message_id' => 'C-2', 'entity_type' => 'product', 'record' => ['name' => 'No key']],
            ['message_id' => 'C-3', 'entity_type' => 'product', 'record' => ['external_id' => 'X3', 'name' => 'Bad GTIN', 'gtin' => '6901234000017']],
            ['message_id' => 'bad id with spaces', 'entity_type' => 'product', 'record' => ['external_id' => 'X4']],
            ['message_id' => 'C-5', 'entity_type' => 'purchase_order', 'record' => ['external_id' => 'PO1']],
            ['message_id' => 'C-6', 'entity_type' => 'spaceship', 'record' => ['x' => 1]],
            ['message_id' => 'C-7', 'entity_type' => 'product', 'record' => ['x', 'y']],
        ])->assertStatus(202);

        $this->assertSame(['accepted', 'accepted', 'accepted', 'rejected', 'rejected', 'rejected', 'rejected'], array_column($response->json('data'), 'status'));
        $this->assertSame(['message_id_invalid', 'entity_not_ingestible', 'entity_unknown', 'record_invalid'], array_column(array_column(array_slice($response->json('data'), 3), 'error'), 'code'));
        $this->assertSame(4, $response->json('run.counts.rejected_at_intake'));

        $this->assertSame('product_match_conflict', $this->messageStatus($key, 'C-1')->json('data.error.code'));
        $missing = $this->messageStatus($key, 'C-2')->json('data.error');
        $this->assertSame('validation_failed', $missing['code']);
        $this->assertSame('external_id', $missing['details']['errors'][0]['field']);
        $this->assertSame('gtin_invalid', $this->messageStatus($key, 'C-3')->json('data.error.code'));
        $this->assertSame(0, $this->inTenant($org, fn () => OrganizationProduct::query()->count()));
        $this->assertNotNull($amox);

        $this->postJson('/api/v1/integration/inbound/messages', ['messages' => array_fill(0, 501, [])], ['Authorization' => "Bearer {$key}"])->assertStatus(422);
    }

    public function test_temporary_failures_are_retried_with_backoff_then_dead_lettered_and_visible(): void
    {
        [$admin, $org] = $this->integrationOrg();
        $this->sampleProducts();
        [$connectorId, $key] = $this->activeConnector($admin, $org);
        $this->sapMappings($connectorId);

        $this->app->bind(ProductHandler::class, fn () => new class implements IntegrationHandler
        {
            public function handle($message, $connector, array $data): array
            {
                throw new RuntimeException('SQLSTATE[HY000] secret-ish internals: password=hunter2');
            }
        });

        $this->inbound($key, [$this->envelopes('materials', 'product', 'MAT')[0]])->assertJsonPath('data.0.status', 'accepted');
        $message = IntegrationMessage::acrossTenants()->where('message_id', 'MAT-0')->first();
        $this->assertSame(['received', 1, 'retry_scheduled'], [$message->status, $message->attempts, $message->error_code]);
        $this->assertTrue($message->next_attempt_at->isFuture());

        for ($i = 0; $i < 6; $i++) {
            $this->travel(61)->minutes();
            $this->artisan('integration:process-due')->assertSuccessful();
        }
        $message->refresh();
        $this->assertSame(['failed', 5, 'processing_error'], [$message->status, $message->attempts, $message->error_code]);

        // Internal error text is logged, never stored or shown to the source system.
        $ack = $this->messageStatus($key, 'MAT-0')->json('data');
        $this->assertStringNotContainsString('hunter2', json_encode($ack));
        $this->assertStringNotContainsString('hunter2', json_encode(IntegrationMessage::acrossTenants()->find($message->id)->getAttributes()));

        $health = collect($this->actingAsApi($admin, $org)->api('GET', 'integration/health')->json('data'))->firstWhere('connector_id', $connectorId);
        $this->assertSame(['failing', 1], [$health['health'], $health['dead_lettered']]);

        // Cause fixed: a manual retry applies it once.
        $this->app->offsetUnset(ProductHandler::class);
        $this->api('POST', "integration/messages/{$message->id}/retry")->assertOk()->assertJsonPath('data.status', 'processed');
        $this->assertSame(1, $this->inTenant($org, fn () => OrganizationProduct::query()->count()));
    }

    public function test_credentials_and_keys_are_protected(): void
    {
        [$admin, $org] = $this->integrationOrg();
        [$connectorId, $key] = $this->activeConnector($admin, $org);

        $this->api('PUT', "integration/connectors/{$connectorId}/credentials", ['credentials' => ['sftp_password' => 'Sup3r-Secret!', 'sftp_username' => 'pharmabot']])
            ->assertOk()->assertJsonPath('data.credentials_set', ['sftp_password', 'sftp_username']);
        $shown = $this->api('GET', "integration/connectors/{$connectorId}")->assertOk()->getContent();
        $raw = DB::table('integration_connectors')->where('id', $connectorId)->first();
        $audit = AuditLog::query()->where('organization_id', $org->id)->get()->toJson();

        foreach ([$shown, json_encode($raw), $audit] as $haystack) {
            $this->assertStringNotContainsString('Sup3r-Secret!', $haystack);
            $this->assertStringNotContainsString($key, $haystack);
        }
        $this->assertSame(hash('sha256', $key), $raw->inbound_key_hash);
        $this->assertSame('Sup3r-Secret!', IntegrationConnector::acrossTenants()->find($connectorId)->credentials['sftp_password'], 'encrypted at rest, readable by the server');

        // Rotation: the old key stops working at once.
        $this->getJson('/api/v1/integration/inbound/ping', ['Authorization' => "Bearer {$key}"])->assertOk()->assertJsonPath('data.connector.code', 'S4');
        $newKey = $this->api('POST', "integration/connectors/{$connectorId}/key")->assertCreated()->json('data.key');
        $this->getJson('/api/v1/integration/inbound/ping', ['Authorization' => "Bearer {$key}"])->assertStatus(401);
        $this->getJson('/api/v1/integration/inbound/ping', ['Authorization' => "Bearer {$newKey}"])->assertOk();
        $this->getJson('/api/v1/integration/inbound/ping')->assertStatus(401);

        // A user session token is not a connector key, and a connector key is not a user session.
        $this->getJson('/api/v1/integration/inbound/ping', ['Authorization' => 'Bearer ck_'.str_repeat('a', 12).'_'.str_repeat('b', 40)])->assertStatus(401);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/integration/connectors', ['Authorization' => "Bearer {$newKey}", 'X-Organization-Id' => $org->id])->assertStatus(401);

        // Plan limit: the trial plan includes one connector.
        $this->actingAsApi($admin, $org)->api('POST', 'integration/connectors', ['code' => 'ORA', 'name' => 'Oracle', 'system_type' => 'oracle_ebs', 'inbound_transport' => 'rest_push'])
            ->assertStatus(403)->assertJsonPath('error.code', 'entitlement_limit_reached');

        // Only integration managers configure connectors.
        [$auditor] = $this->organizationMember(['auditor'], organization: $org);
        $this->actingAsApi($auditor, $org)->api('GET', 'integration/health')->assertOk();
        $this->api('POST', "integration/connectors/{$connectorId}/key")->assertForbidden();
        $this->api('PUT', "integration/connectors/{$connectorId}/credentials", ['credentials' => []])->assertForbidden();
    }

    public function test_signed_requests_rate_limits_and_paused_connectors(): void
    {
        [$admin, $org] = $this->integrationOrg();
        $this->sampleProducts();
        [$connectorId, $key] = $this->activeConnector($admin, $org);
        $secret = $this->api('POST', "integration/connectors/{$connectorId}/signing", ['enabled' => true])->assertOk()->json('data.signing_secret');
        $envelope = ['message_id' => 'SIG-1', 'entity_type' => 'product', 'record' => ['external_id' => 'A1', 'name' => 'Amox', 'gtin' => '6901234000016']];

        $this->inbound($key, [$envelope])->assertStatus(401)->assertJsonPath('error.code', 'signature_invalid');
        $body = json_encode(['messages' => [$envelope]]);
        $sign = fn (int $ts) => ['X-Timestamp' => (string) $ts, 'X-Signature' => 'sha256='.hash_hmac('sha256', $ts.'.'.$body, $secret)];
        $send = fn (array $headers) => $this->call('POST', '/api/v1/integration/inbound/messages', [], [], [],
            $this->transformHeadersToServerVars($headers + ['Authorization' => "Bearer {$key}", 'Content-Type' => 'application/json', 'Accept' => 'application/json']), $body);

        $send($sign(time() - 3600))->assertStatus(401);
        $send(['X-Timestamp' => (string) time(), 'X-Signature' => 'sha256=deadbeef'])->assertStatus(401);
        $send($sign(time()))->assertStatus(202)->assertJsonPath('data.0.status', 'accepted');
        $this->assertSame('processed', IntegrationMessage::acrossTenants()->where('message_id', 'SIG-1')->value('status'));

        // Paused: data is stored and acknowledged but held; resuming processes it.
        $this->api('POST', "integration/connectors/{$connectorId}/signing", ['enabled' => false])->assertOk();
        $this->api('POST', "integration/connectors/{$connectorId}/pause")->assertOk();
        $this->inbound($key, [['message_id' => 'P-1'] + $envelope])->assertJsonPath('data.0.status', 'accepted');
        $this->assertSame('received', IntegrationMessage::acrossTenants()->where('message_id', 'P-1')->value('status'));
        $this->api('POST', "integration/connectors/{$connectorId}/activate")->assertOk();
        $this->assertSame('processed', IntegrationMessage::acrossTenants()->where('message_id', 'P-1')->value('status'));

        // Rate limit per connector, then disabled: the key no longer delivers anything.
        $this->api('PATCH', "integration/connectors/{$connectorId}", ['rate_limit_per_minute' => 2])->assertOk();
        RateLimiter::clear("integration-connector:{$connectorId}");
        $this->getJson('/api/v1/integration/inbound/ping', ['Authorization' => "Bearer {$key}"])->assertOk();
        $this->getJson('/api/v1/integration/inbound/ping', ['Authorization' => "Bearer {$key}"])->assertOk();
        $this->getJson('/api/v1/integration/inbound/ping', ['Authorization' => "Bearer {$key}"])->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
        $this->api('POST', "integration/connectors/{$connectorId}/disable")->assertOk();
        $this->getJson('/api/v1/integration/inbound/ping', ['Authorization' => "Bearer {$key}"])->assertStatus(403);
    }

    public function test_connectors_and_messages_are_isolated_between_organizations(): void
    {
        [$adminA, $orgA] = $this->integrationOrg();
        [$adminB, $orgB] = $this->integrationOrg();
        $this->sampleProducts();
        [$connectorA, $keyA] = $this->activeConnector($adminA, $orgA);
        [, $keyB] = $this->activeConnector($adminB, $orgB);

        $this->inbound($keyA, [['message_id' => 'SAME-ID', 'entity_type' => 'product', 'record' => ['external_id' => 'A1', 'name' => 'Amox', 'gtin' => '6901234000016']]])->assertJsonPath('data.0.status', 'accepted');
        // message_id is unique per connector, not globally.
        $this->inbound($keyB, [['message_id' => 'SAME-ID', 'entity_type' => 'product', 'record' => ['external_id' => 'B1', 'name' => 'Other', 'gtin' => '6901234000016']]])->assertJsonPath('data.0.status', 'accepted');
        $this->assertSame('A1', $this->messageStatus($keyA, 'SAME-ID')->json('data.result.sku'));
        $this->assertSame('B1', $this->messageStatus($keyB, 'SAME-ID')->json('data.result.sku'));

        $idA = IntegrationMessage::acrossTenants()->where('connector_id', $connectorA)->value('id');
        $this->actingAsApi($adminB, $orgB)->api('GET', "integration/messages/{$idA}")->assertNotFound();
        $this->api('GET', "integration/connectors/{$connectorA}")->assertNotFound();
        $this->assertCount(1, $this->api('GET', 'integration/messages')->json('data'));

        // Without the integrations module the connector key is refused.
        DB::table('organization_modules')->where('organization_id', $orgA->id)->where('module', 'integrations')->update(['enabled' => false]);
        $this->getJson('/api/v1/integration/inbound/ping', ['Authorization' => "Bearer {$keyA}"])->assertStatus(403);
    }

    public function test_canonical_model_documents_all_spec_entities(): void
    {
        [$admin, $org] = $this->integrationOrg();
        $model = $this->actingAsApi($admin, $org)->api('GET', 'integration/canonical-model')->assertOk()->json('data');

        $this->assertSame(['product', 'product_identifier', 'regulatory_identifier', 'organization', 'facility', 'warehouse', 'batch', 'serialized_item',
            'inventory_snapshot', 'inventory_movement', 'purchase_order', 'sales_order', 'shipment', 'shipment_item', 'receipt', 'traceability_event', 'recall_event'],
            array_column($model['entities'], 'entity'));
        $this->assertSame(['product', 'batch', 'inventory_snapshot'], array_column(array_filter($model['entities'], fn ($e) => $e['ingestible']), 'entity'));

        // Every entity can be mapped and previewed, even before the gateway applies it.
        [$connectorId] = $this->activeConnector($admin, $org);
        $preview = $this->api('POST', "integration/connectors/{$connectorId}/mappings/purchase_order/preview", ['records' => [
            ['external_id' => 'PO-1', 'supplier_external_id' => 'V-9', 'order_date' => '2026-10-01', 'lines' => [['line_number' => '10', 'product_external_id' => 'M1', 'quantity' => 5]]],
        ]])->assertOk()->json('data.0');
        $this->assertTrue($preview['valid']);
        $this->api('PUT', "integration/connectors/{$connectorId}/mappings/batch", ['mappings' => [['target_field' => 'warp_drive', 'source_path' => 'X']]])
            ->assertStatus(422)->assertJsonPath('error.code', 'mapping_target_unknown');
    }
}

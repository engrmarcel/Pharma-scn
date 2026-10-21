<?php

namespace Tests\Feature;

use App\Domain\FieldForce\Models\FieldOrder;
use App\Domain\FieldForce\Models\FieldVisit;
use App\Domain\Sync\Models\SyncInboxItem;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Concerns\InteractsWithFieldForce;
use Tests\FeatureTestCase;

class SyncTest extends FeatureTestCase
{
    use InteractsWithFieldForce;

    /** Requests made the way the app makes them: with the device's own bearer token. */
    private function asDevice(string $token, $org, string $method, string $uri, array $data = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => "Bearer {$token}", config('platform.tenancy.header') => $org->id])
            ->json($method, '/api/v1/'.$uri, $data);
    }

    private function register(User $user, $org, array $extra = []): array
    {
        return $this->actingAsApi($user, $org)->api('POST', 'devices', $extra + ['name' => 'Rep phone', 'platform' => 'android', 'app_version' => '2.0.0', 'role' => 'field'])
            ->assertCreated()->json('data');
    }

    private function envelope(string $type, array $payload, array $extra = []): array
    {
        return $extra + ['client_txn_id' => (string) Str::ulid(), 'type' => $type, 'schema_version' => 1,
            'client_created_at' => now()->subMinutes(30)->toIso8601String(), 'attempt' => 1, 'payload' => $payload];
    }

    public function test_device_registration_binds_a_token_to_the_device_and_organization(): void
    {
        $t = $this->fieldTeam();
        $reg = $this->register($t['rep1'], $t['org']);
        $this->assertSame('active', $reg['device']['status']);

        $this->asDevice($reg['token'], $t['org'], 'GET', 'sync/status')->assertOk()
            ->assertJsonPath('data.device.id', $reg['device']['id'])->assertJsonPath('data.inbox.synced', 0);

        // Sliding expiry: contact pushes the token's expiry forward.
        $token = PersonalAccessToken::query()->where('device_id', $reg['device']['id'])->sole();
        $this->assertTrue($token->expires_at->greaterThan(now()->addHours(71)));

        // The device token cannot act for another organization of the same user.
        [, $other] = $this->organizationMember();
        $other->memberships()->create(['user_id' => $t['rep1']->id, 'status' => 'active', 'joined_at' => now()]);
        $this->asDevice($reg['token'], $other, 'GET', 'sync/status')->assertForbidden()->assertJsonPath('error.code', 'device_organization_mismatch');

        // Ordinary (non-device) tokens cannot send sync batches.
        $this->actingAsApi($t['rep1'], $t['org'])->api('POST', 'sync/batches', ['envelopes' => [$this->envelope('field.visit.cancel', [])]])
            ->assertForbidden()->assertJsonPath('error.code', 'device_required');

        // Roles: a cashier cannot register a field device; a POS device needs a terminal.
        [$cashier] = $this->organizationMember(['pos_cashier'], organization: $t['org']);
        $this->actingAsApi($cashier, $t['org'])->api('POST', 'devices', ['name' => 'x', 'platform' => 'android', 'role' => 'field'])->assertForbidden();
    }

    public function test_offline_field_work_syncs_once_in_order_and_replays_return_the_stored_acknowledgement(): void
    {
        $t = $this->fieldTeam();
        $token = $this->register($t['rep1'], $t['org'])['token'];
        $visitRef = (string) Str::ulid();

        $batch = ['envelopes' => [
            $this->envelope('field.visit.check_in', ['client_reference' => $visitRef, 'customer_id' => $t['a']->id, 'visit_type' => 'order_taking',
                'captured_offline' => true, 'check_in' => $this->event(['occurred_at' => now()->subMinutes(40)->toIso8601String()])]),
            $this->envelope('field.order.capture', ['client_reference' => (string) Str::ulid(), 'customer_id' => $t['a']->id, 'field_visit_client_reference' => $visitRef,
                'captured_at' => now()->subMinutes(35)->toIso8601String(), 'captured_offline' => true, 'submit' => true,
                'lines' => [['product_id' => $t['product']->id, 'package_level_code' => 'pack', 'quantity' => '1']]]),
            $this->envelope('field.visit.check_out', ['visit_client_reference' => $visitRef, 'outcome' => 'successful', 'discussion_notes' => 'Offline notes',
                'check_out' => $this->event(['occurred_at' => now()->subMinutes(30)->toIso8601String()]), 'activities' => [['activity_type' => 'detailing']]]),
        ]];

        $acks = $this->asDevice($token, $t['org'], 'POST', 'sync/batches', $batch)->assertOk()->json('data');
        $this->assertSame(['synced', 'synced', 'synced'], array_column($acks, 'status'), json_encode($acks));
        $this->assertSame('verified', $acks[0]['result']['check_in_verification']);
        $this->assertSame('accepted', $acks[1]['result']['status']);
        $this->assertTrue($acks[1]['result']['stock_reserved']);
        $this->assertSame('completed', $acks[2]['result']['status']);

        $visit = FieldVisit::acrossTenants()->where('client_reference', $visitRef)->sole();
        $this->assertTrue($visit->captured_offline);
        $this->assertSame($visit->id, FieldOrder::acrossTenants()->sole()->field_visit_id, 'the order is linked to the visit by client reference');

        // The device lost the response and sends the same batch again: nothing is applied twice.
        $again = $this->asDevice($token, $t['org'], 'POST', 'sync/batches', $batch)->assertOk()->json('data');
        $this->assertSame([true, true, true], array_column($again, 'duplicate'));
        $this->assertSame(array_column($acks, 'result'), array_column($again, 'result'));
        $this->assertSame(1, FieldVisit::acrossTenants()->count());
        $this->assertSame(1, FieldOrder::acrossTenants()->count());
        $this->assertSame(3, SyncInboxItem::acrossTenants()->count());

        $this->asDevice($token, $t['org'], 'GET', 'sync/status')->assertJsonPath('data.inbox.synced', 3);
    }

    public function test_refusals_are_kept_with_their_reason_as_conflict_or_rejected(): void
    {
        $t = $this->fieldTeam();
        $token = $this->register($t['rep1'], $t['org'])['token'];
        $checkIn = fn ($customer) => $this->envelope('field.visit.check_in', ['client_reference' => (string) Str::ulid(), 'customer_id' => $customer->id,
            'visit_type' => 'routine', 'check_in' => $this->event()]);

        $acks = $this->asDevice($token, $t['org'], 'POST', 'sync/batches', ['envelopes' => [
            $checkIn($t['a']),
            $checkIn($t['c']),                                          // second open visit: the server's state disagrees
            $checkIn($t['b']),                                          // not this representative's customer
            $this->envelope('field.visit.check_in', ['customer_id' => $t['a']->id]),     // invalid payload
            $this->envelope('pos.unknown', []),                         // a newer app's type this server does not know
            $this->envelope('field.visit.check_in', [], ['client_created_at' => now()->addDay()->toIso8601String()]),
        ]])->assertOk()->json('data');

        $this->assertSame(['synced', 'conflict', 'rejected', 'rejected', 'rejected', 'rejected'], array_column($acks, 'status'));
        $this->assertSame(['visit_in_progress', 'customer_not_found', 'validation_failed', 'unsupported_envelope', 'device_clock_ahead'],
            array_map(fn ($a) => $a['error']['code'], array_slice($acks, 1)));

        // Nothing refused is lost: a reviewer sees it and records how it was handled.
        [$manager] = $this->organizationMember(['sales_manager'], organization: $t['org']);
        $this->actingAsApi($t['rep1'], $t['org'])->api('GET', 'sync/inbox')->assertForbidden();
        $items = $this->actingAsApi($manager, $t['org'])->api('GET', 'sync/inbox', ['unresolved' => 1])->assertOk()->json('data');
        $this->assertCount(5, $items);
        $this->api('POST', "sync/inbox/{$items[0]['id']}/resolve", ['notes' => 'Called the representative'])->assertOk()->assertJsonPath('data.resolution_notes', 'Called the representative');
        $this->assertCount(4, $this->api('GET', 'sync/inbox', ['unresolved' => 1])->json('data'));
        $this->assertNotEmpty($this->api('GET', 'sync/reconciliation')->assertOk()->json('data.devices'));

        // The same transaction id from another device is refused, not merged.
        $other = $this->register($t['rep2'], $t['org'])['token'];
        $stolen = SyncInboxItem::acrossTenants()->first()->client_txn_id;
        $this->asDevice($other, $t['org'], 'POST', 'sync/batches', ['envelopes' => [$this->envelope('field.visit.cancel', [], ['client_txn_id' => $stolen])]])
            ->assertJsonPath('data.0.status', 'rejected')->assertJsonPath('data.0.error.code', 'client_txn_id_conflict');
    }

    public function test_revoked_devices_are_told_once_whether_to_erase_local_data_and_then_locked_out(): void
    {
        $t = $this->fieldTeam();
        $reg = $this->register($t['rep1'], $t['org']);

        // Another representative cannot revoke it; the owner of the device or a device manager can.
        $this->actingAsApi($t['rep2'], $t['org'])->api('POST', "devices/{$reg['device']['id']}/revoke", ['reason' => 'x'])->assertForbidden();
        $this->actingAsApi($t['owner'], $t['org'])->api('POST', "devices/{$reg['device']['id']}/revoke", ['reason' => 'Lost phone', 'wipe' => true])
            ->assertOk()->assertJsonPath('data.status', 'revoked');

        $this->asDevice($reg['token'], $t['org'], 'GET', 'sync/status')->assertUnauthorized()
            ->assertJsonPath('error.code', 'device_revoked')->assertJsonPath('error.details.wipe', true);
        $this->asDevice($reg['token'], $t['org'], 'GET', 'sync/status')->assertUnauthorized()->assertJsonMissingPath('error.code');
        $this->assertSame(0, PersonalAccessToken::query()->where('device_id', $reg['device']['id'])->count());

        $this->actingAsApi($t['owner'], $t['org'])->api('GET', 'devices', ['status' => 'revoked'])->assertOk()->assertJsonPath('data.0.revoke_reason', 'Lost phone');
    }
}

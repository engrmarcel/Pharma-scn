<?php

namespace Tests\Feature;

use App\Domain\FieldForce\Models\FieldVisit;
use App\Domain\Inventory\Models\InventoryBalance;
use App\Domain\Organizations\Models\OrganizationModule;
use App\Domain\Parties\Models\Customer;
use App\Domain\Pos\Models\PosSale;
use App\Domain\Pos\Models\PosShift;
use App\Domain\Pos\Services\PosSessionService;
use App\Domain\Sync\Models\SyncInboxItem;
use App\Domain\Sync\Models\SyncIssue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithInventory;
use Tests\FeatureTestCase;

/**
 * Spec Phase 3 acceptance: "the six-hour outage scenario is tested under a
 * documented device and workload profile" (see docs/OFFLINE_OUTAGE_PLAYBOOK.md).
 *
 * Server half of the scenario. Workload during a 6-hour outage:
 *   - 2 tills, 60 sales each (one every 6 minutes), 5 capsules per sale, cash, every
 *     10th sale paid by standalone card terminal;
 *   - 1 field representative, 12 visits (check-in + check-out) and 6 orders.
 * When the connection returns, each device sends its queue in batches of 50,
 * in capture order; one batch's response is "lost" and the batch is sent again.
 * The device half (local durability across restarts) is covered by the app's
 * outage test (mobile/field_app/test/outage_test.dart).
 */
class SixHourOutageTest extends FeatureTestCase
{
    use InteractsWithInventory;

    private function send(string $token, $org, array $envelopes): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => "Bearer {$token}", config('platform.tenancy.header') => $org->id])
            ->json('POST', '/api/v1/sync/batches', ['envelopes' => $envelopes])->assertOk();
    }

    public function test_six_hours_of_offline_sales_and_visits_sync_completely_once_with_consistent_stock_cash_and_books(): void
    {
        [$owner, $org, $product, $main] = $this->stockedOrganization();
        OrganizationModule::query()->create(['organization_id' => $org->id, 'module' => 'field_force', 'enabled' => true]);
        $this->receive($org, $main, $product, 'OUT-1', '1000', '+2 years', ['unit_cost' => '50']);
        $this->actingAsApi($owner, $org)->api('POST', 'price-lists', ['code' => 'STD', 'name' => 'Standard', 'is_default' => true, 'items' => [
            ['product_id' => $product->id, 'package_level_code' => 'capsule', 'unit_price' => '150'],
            ['product_id' => $product->id, 'package_level_code' => 'pack', 'unit_price' => '3000'],
        ]])->assertCreated();

        // Devices registered and shifts opened before the outage starts.
        $tills = [];
        foreach (['T1', 'T2'] as $code) {
            $terminal = $this->actingAsApi($owner, $org)->api('POST', 'pos/terminals', ['warehouse_id' => $main->id, 'code' => $code, 'name' => $code])->json('data.id');
            [$cashier] = $this->organizationMember(['pos_cashier'], organization: $org);
            $shift = $this->actingAsApi($cashier, $org)->api('POST', 'pos/shifts', ['pos_terminal_id' => $terminal, 'opening_float' => '10000'])->json('data.id');
            $token = $this->api('POST', 'devices', ['name' => $code, 'platform' => 'android', 'role' => 'pos', 'pos_terminal_id' => $terminal])->json('data.token');
            $tills[$code] = compact('terminal', 'shift', 'token');
        }
        [$rep] = $this->organizationMember(['sales_representative'], organization: $org);
        $customer = $this->inTenant($org, fn () => Customer::query()->create(['code' => 'C1', 'name' => 'Clinic', 'type' => 'clinic', 'currency_code' => 'NGN',
            'latitude' => 6.4281, 'longitude' => 3.4219, 'assigned_representative_id' => $rep->id]));
        $repToken = $this->actingAsApi($rep, $org)->api('POST', 'devices', ['name' => 'Rep phone', 'platform' => 'android', 'role' => 'field'])->json('data.token');

        // ---- The outage: everything below is captured on the devices only. ----
        $start = now()->subHours(6);
        $queues = [];
        foreach ($tills as $code => $till) {
            for ($i = 0; $i < 60; $i++) {
                $at = $start->copy()->addMinutes(6 * $i + ($code === 'T2' ? 3 : 0))->toIso8601String();
                $card = $i % 10 === 9;
                $queues[$code][] = ['client_txn_id' => (string) Str::ulid(), 'type' => 'pos.sale', 'schema_version' => 1, 'client_created_at' => $at, 'attempt' => 1,
                    'payload' => ['client_reference' => (string) Str::ulid(), 'shift_id' => $till['shift'], 'completed_at' => $at, 'device_total' => '750.0000',
                        'lines' => [['product_id' => $product->id, 'package_level_code' => 'capsule', 'quantity' => '5', 'unit_price' => '150']],
                        'payments' => [$card ? ['method' => 'card', 'amount' => '750', 'approval_code' => "A{$i}"] : ['method' => 'cash', 'amount' => '750', 'tendered' => '1000']]]];
            }
        }
        $fix = fn ($at) => ['occurred_at' => $at, 'location_status' => 'granted', 'latitude' => 6.4283, 'longitude' => 3.4219, 'accuracy_m' => 15, 'mock_location' => false];
        for ($v = 0; $v < 12; $v++) {
            $in = $start->copy()->addMinutes(30 * $v);
            $ref = (string) Str::ulid();
            $queues['rep'][] = ['client_txn_id' => (string) Str::ulid(), 'type' => 'field.visit.check_in', 'schema_version' => 1, 'client_created_at' => $in->toIso8601String(),
                'payload' => ['client_reference' => $ref, 'customer_id' => $customer->id, 'visit_type' => 'routine', 'captured_offline' => true, 'check_in' => $fix($in->toIso8601String())]];
            if ($v % 2 === 0) {
                $queues['rep'][] = ['client_txn_id' => (string) Str::ulid(), 'type' => 'field.order.capture', 'schema_version' => 1, 'client_created_at' => $in->copy()->addMinutes(10)->toIso8601String(),
                    'payload' => ['client_reference' => (string) Str::ulid(), 'customer_id' => $customer->id, 'field_visit_client_reference' => $ref,
                        'captured_at' => $in->copy()->addMinutes(10)->toIso8601String(), 'captured_offline' => true, 'warehouse_id' => $main->id, 'submit' => true,
                        'lines' => [['product_id' => $product->id, 'package_level_code' => 'pack', 'quantity' => '1']]]];
            }
            $queues['rep'][] = ['client_txn_id' => (string) Str::ulid(), 'type' => 'field.visit.check_out', 'schema_version' => 1, 'client_created_at' => $in->copy()->addMinutes(20)->toIso8601String(),
                'payload' => ['visit_client_reference' => $ref, 'outcome' => 'successful', 'check_out' => $fix($in->copy()->addMinutes(20)->toIso8601String())]];
        }

        // ---- Connection restored: each device sends its queue in batches of 50. ----
        $began = microtime(true);
        $acks = [];
        foreach (['T1' => $tills['T1']['token'], 'T2' => $tills['T2']['token'], 'rep' => $repToken] as $queue => $token) {
            foreach (array_chunk($queues[$queue], 50) as $batch) {
                $acks = array_merge($acks, $this->send($token, $org, $batch)->json('data'));
            }
        }
        // The response to T1's first batch was lost: the device sends it again.
        $replayed = $this->send($tills['T1']['token'], $org, array_slice($queues['T1'], 0, 50))->json('data');
        $elapsed = round(microtime(true) - $began, 1);
        fwrite(STDERR, "\n[six-hour outage] ".count($acks)." envelopes + 50 replays processed in {$elapsed}s\n");

        // Every transaction acknowledged as synced, exactly once.
        $this->assertCount(120 + 12 * 2 + 6, $acks);
        $this->assertSame(['synced'], array_values(array_unique(array_column($acks, 'status'))), json_encode(array_filter($acks, fn ($a) => $a['status'] !== 'synced')));
        $this->assertSame(50, count(array_filter($replayed, fn ($a) => ($a['duplicate'] ?? false) === true)));
        $this->assertSame(150, SyncInboxItem::acrossTenants()->count());
        $this->assertSame(120, PosSale::acrossTenants()->count());
        $this->assertSame(108, PosSale::acrossTenants()->where('status', 'completed')->count(), 'cash sales complete');
        $this->assertSame(12, PosSale::acrossTenants()->where('status', 'awaiting_payment')->count(), 'card sales wait for settlement confirmation');
        $this->assertSame(12, FieldVisit::acrossTenants()->where('status', 'completed')->count());
        $this->assertSame(0, SyncIssue::acrossTenants()->count(), 'enough stock and list prices: nothing to reconcile');

        // Stock: 600 capsules for the tills (completed and reserved) and 6 packs of 20 for the orders.
        $onHand = (string) $this->inTenant($org, fn () => InventoryBalance::query()->sum('quantity'));
        $reserved = (string) $this->inTenant($org, fn () => InventoryBalance::query()->sum('reserved_quantity'));
        $this->assertSame('460.0000', bcadd($onHand, '0', 4), '1000 − 540 issued for completed sales');
        $this->assertSame('180.0000', bcadd($reserved, '0', 4), '60 for card sales + 120 for accepted orders');

        // Cash: each drawer = float + 54 cash sales × 750.
        foreach ($tills as $till) {
            $this->assertSame('50500.0000', $this->inTenant($org, fn () => app(PosSessionService::class)->drawer(PosShift::query()->find($till['shift']))));
        }

        $this->actingAsApi($owner, $org)->api('GET', 'finance/reports/trial-balance')->assertJsonPath('data.balanced', true);
    }
}

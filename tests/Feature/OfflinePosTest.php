<?php

namespace Tests\Feature;

use App\Domain\Inventory\Models\Batch;
use App\Domain\Inventory\Models\InventoryBalance;
use App\Domain\Pos\Models\PosSale;
use App\Domain\Pos\Models\PosShift;
use App\Domain\Pos\Services\PosSessionService;
use App\Domain\Sync\Models\SyncIssue;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithInventory;
use Tests\FeatureTestCase;

class OfflinePosTest extends FeatureTestCase
{
    use InteractsWithInventory;

    /**
     * Pharmacy with 130 capsules in stock (30 expiring soon @40, 100 later @50), 3,000 per pack of 20,
     * two terminals each with a cashier, an open shift and a registered POS device, and a supervisor.
     */
    private function tills(): array
    {
        [$owner, $org, $product, $main] = $this->stockedOrganization();
        [$supervisor] = $this->organizationMember(['pos_supervisor'], organization: $org);
        $early = $this->receive($org, $main, $product, 'P-EARLY', '30', '+3 months', ['unit_cost' => '40']);
        $late = $this->receive($org, $main, $product, 'P-LATE', '100', '+2 years', ['unit_cost' => '50']);
        $this->actingAsApi($owner, $org)->api('POST', 'price-lists', ['code' => 'STD', 'name' => 'Standard', 'is_default' => true,
            'items' => [['product_id' => $product->id, 'package_level_code' => 'pack', 'unit_price' => '3000']]])->assertCreated();

        $tills = [];
        foreach (['T1', 'T2'] as $code) {
            $terminal = $this->actingAsApi($owner, $org)->api('POST', 'pos/terminals', ['warehouse_id' => $main->id, 'code' => $code, 'name' => $code])->assertCreated()->json('data.id');
            [$cashier] = $this->organizationMember(['pos_cashier'], organization: $org);
            $shift = $this->actingAsApi($cashier, $org)->api('POST', 'pos/shifts', ['pos_terminal_id' => $terminal, 'opening_float' => '5000'])->assertCreated()->json('data.id');
            $token = $this->api('POST', 'devices', ['name' => "{$code} tablet", 'platform' => 'android', 'role' => 'pos', 'pos_terminal_id' => $terminal])->assertCreated()->json('data.token');
            $tills[$code] = compact('terminal', 'cashier', 'shift', 'token');
        }

        return compact('owner', 'org', 'product', 'main', 'supervisor', 'early', 'late', 'tills');
    }

    private function sync(array $till, $org, array ...$envelopes): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => "Bearer {$till['token']}", config('platform.tenancy.header') => $org->id])
            ->json('POST', '/api/v1/sync/batches', ['envelopes' => $envelopes]);
    }

    /** A sale captured on the device while offline: packs of the product, paid as given. */
    private function sale(array $t, array $till, string $packs, array $payments, array $line = [], array $extra = []): array
    {
        return ['client_txn_id' => (string) Str::ulid(), 'type' => 'pos.sale', 'schema_version' => 1, 'client_created_at' => now()->subHour()->toIso8601String(),
            'payload' => $extra + ['client_reference' => (string) Str::ulid(), 'shift_id' => $till['shift'], 'completed_at' => now()->subHour()->toIso8601String(),
                'lines' => [$line + ['product_id' => $t['product']->id, 'package_level_code' => 'pack', 'quantity' => $packs, 'unit_price' => '3000']],
                'payments' => $payments]];
    }

    private function trialBalance(array $t): array
    {
        return collect($this->actingAsApi($t['owner'], $t['org'])->api('GET', 'finance/reports/trial-balance')->assertJsonPath('data.balanced', true)->json('data.accounts'))
            ->pluck('balance', 'code')->all();
    }

    public function test_an_offline_cash_sale_is_recorded_once_with_offline_cash_and_the_price_actually_charged(): void
    {
        $t = $this->tills();
        $envelope = $this->sale($t, $t['tills']['T1'], '2', [['method' => 'cash', 'amount' => '5800', 'tendered' => '6000']],
            ['unit_price' => '2900'], ['device_total' => '5800']);

        $ack = $this->sync($t['tills']['T1'], $t['org'], $envelope)->assertOk()->json('data.0');
        $this->assertSame('synced', $ack['status'], json_encode($ack));
        $this->assertSame('completed', $ack['result']['status']);
        $this->assertSame('5800.0000', $ack['result']['total'], 'the price charged at the till stands');
        $this->assertSame('offline_cash_recorded', $ack['result']['payments'][0]['state'], 'cash taken offline is not shown as reconciled');
        $this->assertSame([['type' => 'price_variance', 'severity' => 'medium']], $ack['warnings']);

        $sale = PosSale::acrossTenants()->sole();
        $this->assertTrue($sale->captured_offline);
        $this->assertNotNull($sale->sales_invoice_id);
        $this->assertSame(['0.0000', '0.0000'], $this->balance($t['org'], $t['main'], $t['early']), 'FEFO: the early batch went first');
        $this->assertSame(['90.0000', '0.0000'], $this->balance($t['org'], $t['main'], $t['late']));
        $this->assertSame('10800.0000', $this->inTenant($t['org'], fn () => app(PosSessionService::class)->drawer(PosShift::query()->find($t['tills']['T1']['shift']))), 'float 5,000 + cash 5,800');

        $issue = SyncIssue::acrossTenants()->sole();
        $this->assertSame(['price_variance', 'open', '2900.0000', '3000.0000'], [$issue->type, $issue->status, $issue->details['charged'], $issue->details['list_price']]);

        // The device retries (lost response): same acknowledgement, nothing recorded twice.
        $this->sync($t['tills']['T1'], $t['org'], $envelope)->assertOk()->assertJsonPath('data.0.duplicate', true)->assertJsonPath('data.0.result.number', $sale->number);
        $this->assertSame(1, PosSale::acrossTenants()->count());
        $this->assertSame(1, SyncIssue::acrossTenants()->count());
        $this->trialBalance($t);
    }

    public function test_two_offline_tills_selling_the_last_units_raise_a_shortfall_that_is_reconciled_without_overwriting_stock(): void
    {
        $t = $this->tills();
        // 130 capsules exist. Offline, T1 sells 4 packs (80) and T2 sells 3 packs (60): 10 more than the server knows of.
        $a = $this->sync($t['tills']['T1'], $t['org'], $this->sale($t, $t['tills']['T1'], '4', [['method' => 'cash', 'amount' => '12000']]))->json('data.0');
        $b = $this->sync($t['tills']['T2'], $t['org'], $this->sale($t, $t['tills']['T2'], '3', [['method' => 'cash', 'amount' => '9000']]))->json('data.0');

        $this->assertSame(['synced', 'synced'], [$a['status'], $b['status']]);
        $this->assertNull($a['warnings'] ?? null);
        $this->assertSame([['type' => 'stock_shortfall', 'severity' => 'high']], $b['warnings']);
        $this->assertSame('completed', $b['result']['status'], 'the customer paid: the sale and invoice stand');
        $this->assertSame(1, $b['result']['unallocated_lines']);

        // Stock is issued only as far as it exists; nothing goes negative.
        $this->assertEquals(0, $this->inTenant($t['org'], fn () => InventoryBalance::query()->sum('quantity')));
        $this->assertSame(0, $this->inTenant($t['org'], fn () => InventoryBalance::query()->where('quantity', '<', 0)->count()));

        $issue = SyncIssue::acrossTenants()->where('type', 'stock_shortfall')->sole();
        $this->assertSame('10.0000', (string) $issue->quantity);
        $this->assertSame('pos_sale_line', $issue->subject_type);

        // Reconciliation: a cashier cannot resolve; the supervisor records the 10 capsules that were physically there.
        $this->actingAsApi($t['tills']['T2']['cashier'], $t['org'])->api('POST', "sync/issues/{$issue->id}/resolve", ['action' => 'acknowledge', 'notes' => 'x'])->assertForbidden();
        $this->actingAsApi($t['supervisor'], $t['org']);
        $this->assertSame(1, collect($this->api('GET', 'sync/issues', ['status' => 'open'])->assertOk()->json('data'))->where('type', 'stock_shortfall')->count());
        $this->api('POST', "sync/issues/{$issue->id}/resolve", ['action' => 'record_found_stock', 'notes' => 'Unrecorded delivery found in the back room'])
            ->assertStatus(422)->assertJsonPath('error.code', 'batch_required');
        $this->api('POST', "sync/issues/{$issue->id}/resolve", ['action' => 'record_found_stock', 'notes' => 'Unrecorded delivery found in the back room',
            'batch_number' => 'FOUND-1', 'expiry_date' => now()->addYear()->toDateString(), 'unit_cost' => '45'])
            ->assertOk()->assertJsonPath('data.resolution', 'found_stock_recorded');

        $found = $this->inTenant($t['org'], fn () => Batch::query()->where('batch_number', 'FOUND-1')->sole());
        $this->assertSame(['0.0000', '0.0000'], $this->balance($t['org'], $t['main'], $found), 'received and issued: on-hand stays truthful');
        $line = PosSale::acrossTenants()->where('pos_terminal_id', $t['tills']['T2']['terminal'])->sole()->lines()->sole();
        $this->assertSame('0.0000', (string) $line->unallocated_base_quantity);
        $this->assertSame('60.0000', (string) $line->allocations()->sum('base_quantity'));

        // Books balance, and cost of goods covers all 140 capsules sold: 30@40 + 100@50 + 10@45.
        $this->assertSame('6650.0000', $this->trialBalance($t)['5000']);

        $this->api('POST', "sync/issues/{$issue->id}/resolve", ['action' => 'acknowledge', 'notes' => 'again'])->assertStatus(409);
        $report = $this->api('GET', 'sync/reconciliation')->assertOk()->json('data.devices');
        $this->assertCount(2, $report);
    }

    public function test_offline_card_payments_stay_pending_until_a_supervisor_confirms_settlement(): void
    {
        $t = $this->tills();
        $ack = $this->sync($t['tills']['T1'], $t['org'], $this->sale($t, $t['tills']['T1'], '1', [['method' => 'card', 'amount' => '3000', 'approval_code' => 'AUTH-77']]))->json('data.0');

        $this->assertSame('synced', $ack['status']);
        $this->assertSame('awaiting_payment', $ack['result']['status'], 'not completed on the strength of the device');
        $this->assertSame('payment_pending', $ack['result']['payments'][0]['state']);

        $sale = PosSale::acrossTenants()->sole();
        $payment = $sale->payments()->sole();
        $this->actingAsApi($t['supervisor'], $t['org'])->api('POST', "pos/payments/{$payment->id}/confirm")->assertOk()->assertJsonPath('data.status', 'completed');
    }

    public function test_a_batch_recalled_while_the_till_was_offline_is_still_issued_and_flagged_for_recall_follow_up(): void
    {
        $t = $this->tills();
        $t['late']->forceFill(['recall_status' => 'recalled'])->save();

        $ack = $this->sync($t['tills']['T1'], $t['org'], $this->sale($t, $t['tills']['T1'], '1', [['method' => 'cash', 'amount' => '3000']], ['batch_id' => $t['late']->id]))->json('data.0');
        $this->assertSame('synced', $ack['status']);
        $this->assertSame([['type' => 'blocked_batch_sold', 'severity' => 'high']], $ack['warnings']);
        $this->assertSame(['80.0000', '0.0000'], $this->balance($t['org'], $t['main'], $t['late']), 'the stock that physically left is the recalled batch');
        $this->assertSame('P-LATE', SyncIssue::acrossTenants()->sole()->details['batch_number']);
    }

    public function test_closed_and_reviewed_shifts_and_device_mismatches(): void
    {
        $t = $this->tills();
        $t1 = $t['tills']['T1'];

        // Closed (not yet reviewed): the sale is recorded and flagged, because the count no longer includes it.
        $this->inTenant($t['org'], function () use ($t1) {
            app(TenantContext::class)->set(app(TenantContext::class)->organization());
            $this->actingAs(User::query()->find($t1['cashier']->id));
            app(PosSessionService::class)->closeShift(PosShift::query()->find($t1['shift']), '5000');
        });
        $late = $this->sync($t1, $t['org'], $this->sale($t, $t1, '1', [['method' => 'cash', 'amount' => '3000']]))->json('data.0');
        $this->assertSame('synced', $late['status']);
        $this->assertSame('late_offline_sale', $late['warnings'][0]['type']);

        // Reviewed: the server will not change a signed-off count; the sale waits as a conflict for a supervisor.
        PosShift::acrossTenants()->whereKey($t1['shift'])->update(['status' => 'reviewed']);
        $this->sync($t1, $t['org'], $this->sale($t, $t1, '1', [['method' => 'cash', 'amount' => '3000']]))
            ->assertJsonPath('data.0.status', 'conflict')->assertJsonPath('data.0.error.code', 'shift_reviewed');

        // A device can only send sales for its own terminal's shifts.
        $this->sync($t1, $t['org'], $this->sale($t, $t1, '1', [['method' => 'cash', 'amount' => '3000']], [], ['shift_id' => $t['tills']['T2']['shift']]))
            ->assertJsonPath('data.0.status', 'rejected')->assertJsonPath('data.0.error.code', 'terminal_mismatch');
    }
}

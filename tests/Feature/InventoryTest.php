<?php

namespace Tests\Feature;

use App\Domain\Inventory\Models\InventoryMovement;
use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Inventory\Services\FefoAllocator;
use App\Domain\Inventory\Services\ReservationService;
use App\Domain\Inventory\Services\StockDocumentService;
use App\Support\Exceptions\BusinessRuleViolation;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Concerns\InteractsWithInventory;
use Tests\FeatureTestCase;

class InventoryTest extends FeatureTestCase
{
    use InteractsWithInventory;

    private function idem(): array
    {
        return ['Idempotency-Key' => 'k-'.bin2hex(random_bytes(8))];
    }

    public function test_receipt_converts_package_levels_records_ledger_and_requires_idempotency(): void
    {
        [$owner, $org, $product, $main] = $this->stockedOrganization();
        $body = ['warehouse_id' => $main->id, 'lines' => [[
            'product_id' => $product->id, 'batch_number' => 'lot-a1', 'expiry_date' => now()->addYear()->toDateString(),
            'quantity' => '3', 'package_level_code' => 'pack', 'unit_cost' => '2000.0000',
        ]]];

        $this->actingAsApi($owner, $org)->api('POST', 'inventory/receipts', $body)
            ->assertStatus(400)->assertJsonPath('error.code', 'idempotency_key_required');

        $headers = $this->idem();
        $first = $this->api('POST', 'inventory/receipts', $body, $headers)->assertCreated();
        $this->api('POST', 'inventory/receipts', $body, $headers)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

        // 3 packs × 20 capsules = 60 base units, received exactly once.
        $this->api('GET', "inventory/stock-levels?product_id={$product->id}")
            ->assertOk()->assertJsonPath('data.0.available_on_hand', '60.0000')->assertJsonPath('data.0.sellable', '60.0000');

        $movement = $this->inTenant($org, fn () => InventoryMovement::query()->sole());
        $this->assertSame('60.0000', (string) $movement->quantity);
        $this->assertSame('100.0000', (string) $movement->unit_cost, 'cost per base unit = 2000 / 20');
        $this->assertSame('receipt', $movement->movement_type);
        $this->assertSame($first->json('data.posting_id'), $movement->posting_id);
        $this->assertDatabaseHas('batches', ['batch_number' => 'LOT-A1', 'organization_id' => $org->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'inventory.posted', 'organization_id' => $org->id]);
    }

    public function test_receipt_rules(): void
    {
        [$owner, $org, $product, $main] = $this->stockedOrganization();
        $other = $this->makeProduct(['generic_name' => 'Not stocked', 'identifiers' => []]);
        $this->receive($org, $main, $product, 'LOT-1', '10', '+6 months');
        $this->actingAsApi($owner, $org);

        $line = fn (array $over) => ['warehouse_id' => $main->id, 'lines' => [array_merge(['product_id' => $product->id, 'batch_number' => 'LOT-2', 'quantity' => '5', 'expiry_date' => now()->addYear()->toDateString()], $over)]];

        $this->api('POST', 'inventory/receipts', $line(['product_id' => $other->id]), $this->idem())->assertUnprocessable()->assertJsonPath('error.code', 'product_not_in_catalogue');
        $this->api('POST', 'inventory/receipts', $line(['batch_number' => 'LOT-1']), $this->idem())->assertStatus(409)->assertJsonPath('error.code', 'batch_expiry_mismatch');
        $this->api('POST', 'inventory/receipts', $line(['expiry_date' => now()->subDay()->toDateString()]), $this->idem())->assertUnprocessable()->assertJsonPath('error.code', 'expired_on_receipt');
        $this->api('POST', 'inventory/receipts', $line(['package_level_code' => 'pallet']), $this->idem())->assertUnprocessable()->assertJsonPath('error.code', 'package_level_unknown');

        // Expired goods can still be recorded, but only into quarantine.
        $this->api('POST', 'inventory/receipts', $line(['batch_number' => 'OLD', 'expiry_date' => now()->subDay()->toDateString(), 'quality_status' => 'quarantined']), $this->idem())->assertCreated();
        $this->api('GET', "inventory/stock-levels?product_id={$product->id}")->assertJsonPath('data.0.quarantined', '5.0000')->assertJsonPath('data.0.sellable', '10.0000');
    }

    public function test_fefo_allocates_earliest_expiry_first_and_excludes_blocked_batches(): void
    {
        [$owner, $org, $product, $main] = $this->stockedOrganization();
        $late = $this->receive($org, $main, $product, 'LATE', '50', '+2 years');
        $early = $this->receive($org, $main, $product, 'EARLY', '30', '+3 months');
        $noExpiry = $this->receive($org, $main, $product, 'NOEXP', '100', null);
        $expired = $this->receive($org, $main, $product, 'EXPIRED', '40', '+1 day');
        $recalled = $this->receive($org, $main, $product, 'RECALL', '40', '+1 month');
        $this->inTenant($org, fn () => $recalled->forceFill(['recall_status' => 'recalled'])->save());
        $this->travel(2)->days();

        $plan = $this->inTenant($org, fn () => app(FefoAllocator::class)->allocate($product->id, $main->id, '90'));
        $this->assertSame([$early->id => '30.0000', $late->id => '50.0000', $noExpiry->id => '10.0000'], $plan);
        $this->assertArrayNotHasKey($expired->id, $plan);

        // Minimum remaining shelf life excludes the batch expiring in ~3 months.
        $this->actingAsApi($owner, $org)->api('PATCH', 'inventory/settings', ['min_shelf_life_days' => 120])->assertOk();
        $this->api('POST', 'inventory/allocations/preview', ['product_id' => $product->id, 'warehouse_id' => $main->id, 'quantity' => 10])
            ->assertOk()->assertJsonPath('data.0.batch_id', $late->id);

        $this->api('POST', 'inventory/allocations/preview', ['product_id' => $product->id, 'warehouse_id' => $main->id, 'quantity' => 1000])
            ->assertStatus(409)->assertJsonPath('error.code', 'insufficient_stock')->assertJsonPath('error.details.sellable', '150.0000');
    }

    public function test_adjustments_cannot_create_negative_stock_or_sellable_blocked_stock(): void
    {
        [$owner, $org, $product, $main] = $this->stockedOrganization();
        $batch = $this->receive($org, $main, $product, 'B1', '10');
        $expired = $this->receive($org, $main, $product, 'B2', '5', '+1 day');
        $this->travel(2)->days();
        $this->actingAsApi($owner, $org);

        $adjust = fn (string $batchId, string $delta, string $reason = 'damage', string $state = 'available') => $this->api('POST', 'stock-adjustments', [
            'warehouse_id' => $main->id, 'reason_code' => $reason, 'lines' => [['batch_id' => $batchId, 'quantity_delta' => $delta, 'stock_state' => $state]],
        ], $this->idem());

        $adjust($batch->id, '-11')->assertStatus(409)->assertJsonPath('error.code', 'insufficient_stock');
        $this->assertSame(['10.0000', '0.0000'], $this->balance($org, $main, $batch));

        $adjust($batch->id, '-2')->assertCreated()->assertJsonPath('data.number', 'ADJ-000001');
        $this->assertSame(['8.0000', '0.0000'], $this->balance($org, $main, $batch));
        $this->assertSame('write_off', $this->inTenant($org, fn () => InventoryMovement::query()->where('reason_code', 'damage')->value('movement_type')));

        $adjust($expired->id, '3', 'found')->assertStatus(409)->assertJsonPath('error.code', 'batch_not_sellable');
        $adjust($expired->id, '3', 'found', 'quarantined')->assertCreated()->assertJsonPath('data.number', 'ADJ-000002');
    }

    public function test_negative_available_stock_only_when_policy_allows(): void
    {
        [$owner, $org, $product, $main] = $this->stockedOrganization();
        $batch = $this->receive($org, $main, $product, 'B1', '1');
        $this->actingAsApi($owner, $org);
        $body = ['warehouse_id' => $main->id, 'reason_code' => 'other', 'notes' => 'test', 'lines' => [['batch_id' => $batch->id, 'quantity_delta' => '-3']]];

        $this->api('POST', 'stock-adjustments', $body, $this->idem())->assertStatus(409);
        $this->api('PATCH', 'inventory/settings', ['allow_negative_stock' => true])->assertOk();
        $this->api('POST', 'stock-adjustments', $body, $this->idem())->assertCreated();
        $this->assertSame(['-2.0000', '0.0000'], $this->balance($org, $main, $batch));
    }

    public function test_reservations_hold_stock_then_consume_or_release(): void
    {
        [$owner, $org, $product, $main] = $this->stockedOrganization();
        $batch = $this->receive($org, $main, $product, 'B1', '10');
        $this->actingAsApi($owner, $org);

        $this->inTenant($org, fn () => app(ReservationService::class)->reserve($product->id, $main->id, '8', 'test_order', '01JTESTORDER00000000000000'));
        $this->assertSame(['10.0000', '8.0000'], $this->balance($org, $main, $batch));

        // Reserved stock cannot be allocated again or adjusted away.
        $this->api('POST', 'inventory/allocations/preview', ['product_id' => $product->id, 'warehouse_id' => $main->id, 'quantity' => 3])->assertStatus(409);
        $this->api('POST', 'stock-adjustments', ['warehouse_id' => $main->id, 'reason_code' => 'damage', 'lines' => [['batch_id' => $batch->id, 'quantity_delta' => '-3']]], $this->idem())
            ->assertStatus(409)->assertJsonPath('error.code', 'stock_reserved');

        $this->inTenant($org, fn () => app(ReservationService::class)->consume('test_order', '01JTESTORDER00000000000000'));
        $this->assertSame(['2.0000', '0.0000'], $this->balance($org, $main, $batch));
        $this->assertSame('consumed', $this->inTenant($org, fn () => StockReservation::query()->value('status')));

        $this->inTenant($org, fn () => app(ReservationService::class)->reserve($product->id, $main->id, '2', 'test_order', '01JTESTORDER00000000000001'));
        $this->assertSame(1, $this->inTenant($org, fn () => app(ReservationService::class)->release('test_order', '01JTESTORDER00000000000001')));
        $this->assertSame(['2.0000', '0.0000'], $this->balance($org, $main, $batch));
    }

    public function test_quarantine_moves_all_stock_releases_reservations_and_blocks_sale(): void
    {
        [$owner, $org, $product, $main, $annex] = $this->stockedOrganization();
        $batch = $this->receive($org, $main, $product, 'Q1', '10');
        $this->receive($org, $annex, $product, 'Q1', '4');
        $this->inTenant($org, fn () => app(ReservationService::class)->reserve($product->id, $main->id, '5', 'test_order', '01JTESTORDER00000000000002'));
        $this->actingAsApi($owner, $org);

        $this->api('POST', "batches/{$batch->id}/quarantine", ['reason' => 'Suspected falsified packaging'])
            ->assertOk()->assertJsonPath('data.quality_status', 'quarantined')->assertJsonPath('data.sale_block_reason', 'quarantined');

        $this->assertSame(['0.0000', '0.0000'], $this->balance($org, $main, $batch));
        $this->assertSame(['10.0000', '0.0000'], $this->balance($org, $main, $batch, 'quarantined'));
        $this->assertSame(['4.0000', '0.0000'], $this->balance($org, $annex, $batch, 'quarantined'));
        $this->assertSame('released', $this->inTenant($org, fn () => StockReservation::query()->value('status')));
        $this->api('POST', 'inventory/allocations/preview', ['product_id' => $product->id, 'warehouse_id' => $main->id, 'quantity' => 1])->assertStatus(409);

        $this->api('POST', "batches/{$batch->id}/release", ['reason' => 'Verified genuine with manufacturer'])->assertOk();
        $this->assertSame(['10.0000', '0.0000'], $this->balance($org, $main, $batch));

        $expired = $this->receive($org, $main, $product, 'X1', '1', '+1 day');
        $this->api('POST', "batches/{$expired->id}/quarantine", ['reason' => 'x'])->assertOk();
        $this->travel(2)->days();
        $this->api('POST', "batches/{$expired->id}/release", ['reason' => 'x'])->assertStatus(409)->assertJsonPath('error.code', 'batch_release_blocked');
    }

    public function test_transfer_dispatch_uses_fefo_tracks_in_transit_and_supports_partial_receipt(): void
    {
        [$owner, $org, $product, $main, $annex] = $this->stockedOrganization();
        $early = $this->receive($org, $main, $product, 'E', '6', '+2 months');
        $late = $this->receive($org, $main, $product, 'L', '20', '+1 year');
        $this->actingAsApi($owner, $org);

        $id = $this->api('POST', 'stock-transfers', ['from_warehouse_id' => $main->id, 'to_warehouse_id' => $annex->id, 'lines' => [['product_id' => $product->id, 'quantity' => '10']]])
            ->assertCreated()->assertJsonPath('data.number', 'TRF-000001')->json('data.id');

        $lines = collect($this->api('POST', "stock-transfers/{$id}/dispatch")->assertOk()->assertJsonPath('data.status', 'dispatched')->json('data.lines'));
        $this->assertEqualsCanonicalizing([[$early->id, '6.0000'], [$late->id, '4.0000']], $lines->map(fn ($l) => [$l['batch_id'], $l['quantity']])->all());
        $this->assertSame(['0.0000', '0.0000'], $this->balance($org, $main, $early));
        $this->assertSame(['6.0000', '0.0000'], $this->balance($org, $annex, $early, 'in_transit'));

        $earlyLine = $lines->firstWhere('batch_id', $early->id)['id'];
        $lateLine = $lines->firstWhere('batch_id', $late->id)['id'];
        $this->api('POST', "stock-transfers/{$id}/receive", ['lines' => [['line_id' => $earlyLine, 'quantity' => '7']]], $this->idem())->assertUnprocessable();
        $this->api('POST', "stock-transfers/{$id}/receive", ['lines' => [['line_id' => $earlyLine, 'quantity' => '6'], ['line_id' => $lateLine, 'quantity' => '3']]], $this->idem())
            ->assertOk()->assertJsonPath('data.status', 'partially_received');
        $this->assertSame(['3.0000', '0.0000'], $this->balance($org, $annex, $late));
        $this->assertSame(['1.0000', '0.0000'], $this->balance($org, $annex, $late, 'in_transit'), 'unreceived stock stays visible in transit');

        $this->api('POST', "stock-transfers/{$id}/receive", ['lines' => [['line_id' => $lateLine, 'quantity' => '1']]], $this->idem())->assertJsonPath('data.status', 'received');
        $this->api('POST', "stock-transfers/{$id}/cancel")->assertStatus(409);
    }

    public function test_stock_count_posts_differences_against_live_balance(): void
    {
        [$owner, $org, $product, $main] = $this->stockedOrganization();
        $a = $this->receive($org, $main, $product, 'A', '10');
        $b = $this->receive($org, $main, $product, 'B', '5');
        $this->actingAsApi($owner, $org);

        $id = $this->api('POST', 'stock-counts', ['warehouse_id' => $main->id])->assertCreated()->json('data.id');
        $this->api('PUT', "stock-counts/{$id}/lines", ['lines' => [['batch_id' => $a->id, 'counted_quantity' => '8'], ['batch_id' => $b->id, 'counted_quantity' => '5']]])->assertOk();
        $this->api('POST', "stock-counts/{$id}/post")->assertOk()->assertJsonPath('data.status', 'posted');

        $this->assertSame(['8.0000', '0.0000'], $this->balance($org, $main, $a));
        $this->assertSame(1, $this->inTenant($org, fn () => InventoryMovement::query()->where('movement_type', 'count')->count()), 'only the batch with a difference is adjusted');
        $this->api('POST', "stock-counts/{$id}/post")->assertStatus(409);
    }

    public function test_warehouse_scoped_member_only_sees_and_moves_stock_in_assigned_warehouses(): void
    {
        [$owner, $org, $product, $main, $annex] = $this->stockedOrganization();
        $batch = $this->receive($org, $main, $product, 'B1', '10');
        $this->receive($org, $annex, $product, 'B1', '7');
        [$clerk, , $membership] = $this->organizationMember(['warehouse_officer'], organization: $org);

        $this->actingAsApi($owner, $org)->api('PUT', "members/{$membership->id}/locations", ['all_locations' => false, 'warehouse_ids' => [$annex->id]])->assertOk();

        $this->actingAsApi($clerk, $org)->api('GET', 'inventory/balances')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.warehouse_id', $annex->id);
        $this->api('POST', 'inventory/receipts', ['warehouse_id' => $main->id, 'lines' => [['product_id' => $product->id, 'batch_number' => 'B1', 'quantity' => 1]]], $this->idem())
            ->assertForbidden()->assertJsonPath('error.code', 'location_access_denied');
        $this->api('POST', 'stock-transfers', ['from_warehouse_id' => $main->id, 'to_warehouse_id' => $annex->id, 'lines' => [['product_id' => $product->id, 'quantity' => 1]]])
            ->assertForbidden();
        $this->api('GET', "batches/{$batch->id}")->assertOk()->assertJsonCount(1, 'data.balances');
    }

    public function test_inventory_is_isolated_between_tenants(): void
    {
        [$ownerA, $orgA, , $mainA] = $this->stockedOrganization();
        [, $orgB, $productB, $mainB] = $this->stockedOrganization();
        $foreignBatch = $this->receive($orgB, $mainB, $productB, 'SECRET', '9');

        $this->actingAsApi($ownerA, $orgA);
        $this->api('GET', 'inventory/balances')->assertJsonCount(0, 'data');
        $this->api('GET', "batches/{$foreignBatch->id}")->assertNotFound();
        $this->api('POST', 'stock-adjustments', ['warehouse_id' => $mainA->id, 'reason_code' => 'damage', 'lines' => [['batch_id' => $foreignBatch->id, 'quantity_delta' => '-1']]], $this->idem())
            ->assertNotFound()->assertJsonPath('error.code', 'stock_reference_invalid');
        $this->api('POST', 'stock-counts', ['warehouse_id' => $mainB->id])->assertUnprocessable()->assertJsonValidationErrors('warehouse_id');
        $this->assertSame(['9.0000', '0.0000'], $this->balance($orgB, $mainB, $foreignBatch));
    }

    public function test_costs_are_hidden_without_valuation_permission(): void
    {
        [$owner, $org, $product, $main] = $this->stockedOrganization();
        $batch = $this->receive($org, $main, $product, 'B1', '10', '+1 year', ['unit_cost' => '50']);
        [$cashier] = $this->organizationMember(['pos_cashier'], organization: $org);

        $this->actingAsApi($cashier, $org)->api('GET', "batches/{$batch->id}")->assertOk()->assertJsonMissingPath('data.unit_cost');
        $this->api('GET', 'inventory/movements')->assertOk()->assertJsonMissingPath('data.0.unit_cost');
        $this->api('GET', 'inventory/valuation')->assertForbidden();

        $this->actingAsApi($owner, $org)->api('GET', 'inventory/valuation')->assertOk()->assertJsonPath('data.0.value', '500.0000');
    }

    public function test_ledger_entries_are_immutable_and_reconcile_with_balances(): void
    {
        [, $org, $product, $main, $annex] = $this->stockedOrganization();
        $this->receive($org, $main, $product, 'B1', '10');
        $this->receive($org, $annex, $product, 'B2', '4');

        // Every balance equals the sum of its movements.
        $mismatches = DB::select('SELECT b.id FROM inventory_balances b
            LEFT JOIN (SELECT warehouse_id, batch_id, stock_state, SUM(quantity) q FROM inventory_movements GROUP BY warehouse_id, batch_id, stock_state) m
              ON m.warehouse_id = b.warehouse_id AND m.batch_id = b.batch_id AND m.stock_state = b.stock_state
            WHERE b.quantity <> COALESCE(m.q, 0)');
        $this->assertSame([], $mismatches);

        $this->expectException(LogicException::class);
        $this->inTenant($org, fn () => InventoryMovement::query()->first()->update(['quantity' => 999]));
    }

    public function test_failed_posting_rolls_back_entirely(): void
    {
        [, $org, $product, $main] = $this->stockedOrganization();
        $ok = $this->receive($org, $main, $product, 'OK', '10');
        $low = $this->receive($org, $main, $product, 'LOW', '1');
        $movementsBefore = $this->inTenant($org, fn () => InventoryMovement::query()->count());

        try {
            $this->inTenant($org, fn () => app(StockDocumentService::class)->adjust($main, 'damage', [
                ['batch_id' => $ok->id, 'quantity_delta' => '-5'],
                ['batch_id' => $low->id, 'quantity_delta' => '-5'],
            ]));
            $this->fail('Expected insufficient stock.');
        } catch (BusinessRuleViolation $e) {
            $this->assertSame('insufficient_stock', $e->errorCode);
        }

        $this->assertSame(['10.0000', '0.0000'], $this->balance($org, $main, $ok), 'first line must not survive a failed posting');
        $this->assertSame($movementsBefore, $this->inTenant($org, fn () => InventoryMovement::query()->count()));
        $this->assertDatabaseCount('stock_adjustments', 0);
    }

    public function test_inventory_roles(): void
    {
        [$owner, $org, $product, $main] = $this->stockedOrganization();
        $batch = $this->receive($org, $main, $product, 'B1', '10');
        [$cashier] = $this->organizationMember(['pos_cashier'], organization: $org);
        [$pharmacist] = $this->organizationMember(['pharmacist'], organization: $org);

        $this->actingAsApi($cashier, $org)->api('GET', 'inventory/stock-levels')->assertOk();
        $this->api('POST', 'stock-adjustments', ['warehouse_id' => $main->id, 'reason_code' => 'damage', 'lines' => [['batch_id' => $batch->id, 'quantity_delta' => '-1']]], $this->idem())->assertForbidden();
        $this->api('POST', "batches/{$batch->id}/quarantine", ['reason' => 'x'])->assertForbidden();

        $this->actingAsApi($pharmacist, $org)->api('POST', "batches/{$batch->id}/quarantine", ['reason' => 'Damaged seal'])->assertOk();
        $count = $this->api('POST', 'stock-counts', ['warehouse_id' => $main->id])->assertCreated()->json('data.id');
        // Posting a count changes stock and needs adjustment rights the pharmacist does not have.
        $this->api('POST', "stock-counts/{$count}/post")->assertForbidden();
    }
}

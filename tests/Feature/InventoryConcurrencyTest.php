<?php

namespace Tests\Feature;

use App\Domain\Inventory\Models\Batch;
use App\Domain\Inventory\Services\InventoryLedger;
use App\Domain\Inventory\Support\StockLine;
use App\Support\Exceptions\BusinessRuleViolation;
use Database\Seeders\TestingSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithInventory;
use Tests\Concerns\InteractsWithPlatform;
use Tests\TestCase;

/**
 * Real two-connection locking test (spec §13.4, §29.2 #13). Uses committed
 * data (truncation instead of a wrapping transaction) so a second database
 * session can see and lock the same balance row.
 */
class InventoryConcurrencyTest extends TestCase
{
    use DatabaseTruncation, InteractsWithInventory, InteractsWithPlatform;

    protected bool $seed = true;

    protected string $seeder = TestingSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();
        $default = config('database.default');
        config(['database.connections.concurrent' => config("database.connections.{$default}")]);
    }

    /**
     * This test commits data; leave the database as transaction-based tests expect it
     * (reference data only), whatever order the suite runs in.
     */
    protected function tearDown(): void
    {
        $this->truncateTablesForAllConnections();
        $this->seed(TestingSeeder::class);

        parent::tearDown();
    }

    public function test_a_locked_balance_blocks_a_competing_posting_instead_of_overselling(): void
    {
        [, $org, $product, $main] = $this->stockedOrganization();
        $batch = $this->receive($org, $main, $product, 'LAST', '10');

        // "Terminal B" holds the balance row inside an open transaction.
        $other = DB::connection('concurrent');
        $other->beginTransaction();
        $other->select("SELECT quantity FROM inventory_balances WHERE batch_id = ? AND stock_state = 'available' FOR UPDATE", [$batch->id]);

        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
        $sell = fn (string $qty) => $this->inTenant($org, fn () => app(InventoryLedger::class)->post([
            new StockLine($main->id, $batch->id, 'available', '-'.$qty, 'sale'),
        ], 'sale'));

        try {
            $sell('10');
            $this->fail('The posting must wait for the lock, not proceed.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('Lock wait timeout', $e->getMessage());
        } finally {
            $other->rollBack();
            DB::disconnect('concurrent');
        }

        // With the lock released, the last units sell once; a second sale cannot oversell.
        $sell('10');
        try {
            $sell('1');
            $this->fail('Overselling must be refused.');
        } catch (BusinessRuleViolation $e) {
            $this->assertSame('insufficient_stock', $e->errorCode);
        }

        $this->assertSame('0.0000', (string) DB::table('inventory_balances')->where('batch_id', $batch->id)->where('stock_state', 'available')->value('quantity'));
        // The ledger agrees: receipt +10, sale -10, nothing else.
        $this->assertSame(0, bccomp((string) DB::table('inventory_movements')->where('batch_id', $batch->id)->sum('quantity'), '0', 4));
        $this->assertSame(2, DB::table('inventory_movements')->where('batch_id', $batch->id)->count());
        $this->assertSame(1, Batch::acrossTenants()->count());
    }
}

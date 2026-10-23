<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithInventory;
use Tests\FeatureTestCase;

class ConsistencyCheckTest extends FeatureTestCase
{
    use InteractsWithInventory;

    public function test_invariants_hold_after_normal_work_and_a_tampered_balance_is_reported(): void
    {
        [, $org, $product, $main] = $this->stockedOrganization();
        $this->receive($org, $main, $product, 'CON-1', '40', '+1 year');

        $this->artisan('ops:consistency', ['--organization' => $org->id])->assertSuccessful()->expectsOutputToContain('All invariants hold.');

        // A balance edited outside the application no longer matches its movements.
        DB::table('inventory_balances')->where('organization_id', $org->id)->limit(1)->update(['quantity' => DB::raw('quantity + 1')]);
        $this->artisan('ops:consistency', ['--organization' => $org->id])->assertFailed()->expectsOutputToContain('balances_match_movements');
    }
}

<?php

namespace Tests\Feature;

use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\DB;
use Tests\FeatureTestCase;

/** The synthetic demo data loads completely, including a marketplace with something to trade. */
class DemoSeederTest extends FeatureTestCase
{
    public function test_the_demo_data_loads_and_the_marketplace_has_suppliers_offers_and_trading(): void
    {
        $this->seed(DemoSeeder::class);

        $this->assertSame(7, DB::table('organizations')->where('name', 'like', 'Demo%')->count());
        $this->assertSame(7, DB::table('organization_modules')->where('module', 'marketplace')->where('enabled', true)->count(), 'every demo organization trades');
        $this->assertSame(4, DB::table('marketplace_profiles')->where('listed', true)->count());
        $this->assertGreaterThanOrEqual(10, DB::table('marketplace_listings')->where('status', 'active')->count());
        $this->assertSame(1, DB::table('marketplace_listings')->whereNotNull('allowed_buyer_types')->where('package_level_code', 'pack')->count());
        $this->assertSame(['approved' => 3, 'requested' => 2], DB::table('trading_relationships')->groupBy('status')->selectRaw('status, COUNT(*) as n')->pluck('n', 'status')->map(fn ($n) => (int) $n)->sortKeys()->all());
        $this->assertSame(['completed', 'submitted'], DB::table('marketplace_orders')->orderBy('status')->pluck('status')->map(fn ($s) => $s === 'received' ? 'completed' : $s)->all());
        $this->assertSame(1, DB::table('rfqs')->where('status', 'open')->count());
        $this->assertSame(1, DB::table('marketplace_quotes')->count());

        // Seeding again changes nothing.
        $counts = fn () => [DB::table('marketplace_listings')->count(), DB::table('marketplace_orders')->count(), DB::table('trading_relationships')->count()];
        $before = $counts();
        $this->seed(DemoSeeder::class);
        $this->assertSame($before, $counts());
    }
}

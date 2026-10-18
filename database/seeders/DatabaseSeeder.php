<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Reference data and the permission registry are required in every
     * environment; demo data only outside production.
     */
    public function run(): void
    {
        $this->call([ReferenceDataSeeder::class, RolesAndPermissionsSeeder::class, SubscriptionPlanSeeder::class]);

        if (! app()->isProduction()) {
            $this->call(DemoSeeder::class);
        }
    }
}

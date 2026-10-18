<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/** Baseline for automated tests: reference data and permissions, no demo data. */
class TestingSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([ReferenceDataSeeder::class, RolesAndPermissionsSeeder::class, SubscriptionPlanSeeder::class]);
    }
}

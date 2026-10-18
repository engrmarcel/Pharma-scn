<?php

namespace Tests;

use Database\Seeders\TestingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithPlatform;

abstract class FeatureTestCase extends TestCase
{
    use InteractsWithPlatform, RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = TestingSeeder::class;
}

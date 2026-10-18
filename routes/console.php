<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// GPS retention: raw visit coordinates are removed after field_force.location_retention_days.
Schedule::command('field-force:purge-locations')->dailyAt('02:30')->withoutOverlapping();

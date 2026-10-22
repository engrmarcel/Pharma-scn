<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// GPS retention: raw visit coordinates are removed after field_force.location_retention_days.
// Integration inbox safety net: due retries and messages a crashed worker left behind.
Schedule::command('integration:process-due')->everyMinute()->withoutOverlapping();
Schedule::command('integration:sftp-poll')->everyFiveMinutes()->withoutOverlapping();

// CRM follow-up automation (missed plans, overdue visits, stale opportunities, escalations).
Schedule::command('crm:automation')->dailyAt('05:30')->withoutOverlapping();

Schedule::command('field-force:purge-locations')->dailyAt('02:30')->withoutOverlapping();

// Analytical store: recompute recent daily demand facts (late and offline postings included).
Schedule::command('analytics:refresh')->dailyAt('01:30')->withoutOverlapping();
// Recommendations (advisory): reorder, overstock, expiry risk, integration and traceability anomalies.
Schedule::command('analytics:recommend')->dailyAt('02:00')->withoutOverlapping();

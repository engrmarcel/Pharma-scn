<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Every task runs on one server only (Phase 10d): with several app instances each runs the scheduler,
// and the shared cache lock lets exactly one of them start each task.

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// GPS retention: raw visit coordinates are removed after field_force.location_retention_days.
// Integration inbox safety net: due retries and messages a crashed worker left behind.
Schedule::command('integration:process-due')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('integration:sftp-poll')->everyFiveMinutes()->withoutOverlapping()->onOneServer();

// CRM follow-up automation (missed plans, overdue visits, stale opportunities, escalations).
Schedule::command('crm:automation')->dailyAt('05:30')->withoutOverlapping()->onOneServer();

Schedule::command('field-force:purge-locations')->dailyAt('02:30')->withoutOverlapping()->onOneServer();

// Analytical store: recompute recent daily demand facts (late and offline postings included).
Schedule::command('analytics:refresh')->dailyAt('01:30')->withoutOverlapping()->onOneServer();
// Recommendations (advisory): reorder, overstock, expiry risk, integration and traceability anomalies.
Schedule::command('analytics:recommend')->dailyAt('02:00')->withoutOverlapping()->onOneServer();
Schedule::command('analytics:purge-assistant-log')->dailyAt('02:45')->withoutOverlapping()->onOneServer();

// Operational monitoring (Phase 10b): heartbeats of the scheduler and queue workers, threshold
// checks that open and resolve alerts, and the nightly consistency check of ledgers and chains.
Schedule::command('ops:heartbeat')->everyMinute()->onOneServer();
Schedule::command('ops:monitor')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('ops:consistency --alert')->dailyAt('03:30')->withoutOverlapping()->onOneServer();

// Logical backups and monthly restore drills (Phase 10c), when this deployment runs them.
Schedule::command('ops:backup')->dailyAt('02:15')->withoutOverlapping()->when(fn () => config('backup.enabled'))->onOneServer();
Schedule::command('ops:restore-drill')->monthlyOn(1, '04:00')->withoutOverlapping()->when(fn () => config('backup.enabled'))->onOneServer();

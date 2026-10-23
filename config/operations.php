<?php

/*
| Operational monitoring (Phase 10b, spec §31 "queue backlogs and failures are observable").
| `ops:monitor` runs every minute, compares these figures with the thresholds and opens or
| resolves alerts; platform operations staff are e-mailed when an alert opens and when it clears.
*/

return [
    // Queues to watch (pending size and age of the oldest pending job).
    'queues' => array_filter(explode(',', (string) env('OPS_MONITORED_QUEUES', 'default'))),

    // Each check: warning and critical thresholds; null disables that level.
    'thresholds' => [
        'queue_oldest_pending_seconds' => ['warning' => 300, 'critical' => 900],
        'queue_pending_jobs' => ['warning' => 1000, 'critical' => 5000],
        'failed_jobs_last_hour' => ['warning' => 1, 'critical' => 20],
        'scheduler_heartbeat_age_seconds' => ['warning' => 180, 'critical' => 600],
        'queue_heartbeat_age_seconds' => ['warning' => 300, 'critical' => 900],
        'webhook_deliveries_overdue' => ['warning' => 50, 'critical' => 500],
        'webhook_deliveries_dead_last_day' => ['warning' => 1, 'critical' => 50],
        'integration_inbox_backlog' => ['warning' => 500, 'critical' => 5000],
        'integration_inbox_oldest_seconds' => ['warning' => 900, 'critical' => 3600],
        'integration_quarantined' => ['warning' => 1, 'critical' => 100],
        'sync_inbox_backlog' => ['warning' => 200, 'critical' => 2000],
        'sync_issues_open_high' => ['warning' => 1, 'critical' => 50],
    ],

    // A webhook delivery is overdue when its next attempt is this late.
    'webhook_overdue_seconds' => 600,

    // Prometheus-format metrics at GET /api/v1/metrics, for a scraper presenting this bearer token.
    // Leave empty to disable the endpoint.
    'metrics_token' => env('OPS_METRICS_TOKEN'),
];

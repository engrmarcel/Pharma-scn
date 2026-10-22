<?php

/*
|--------------------------------------------------------------------------
| Analytics and AI (spec §19, Phase 8)
|--------------------------------------------------------------------------
|
| Analytical reads go through their own connection so they can be moved to a
| read replica without touching transactional code. When ANALYTICS_DB_CONNECTION
| is empty they use the default connection (development, tests, small installs).
|
*/

return [

    // Name of a connection in config/database.php (e.g. "analytics_replica"); null = default connection.
    'connection' => env('ANALYTICS_DB_CONNECTION'),

    // Daily demand facts: how far back a full rebuild goes, and how many recent days the nightly refresh recomputes
    // (late postings, offline POS sales synchronised days later).
    'demand_history_days' => (int) env('ANALYTICS_DEMAND_HISTORY_DAYS', 400),
    'refresh_recent_days' => (int) env('ANALYTICS_REFRESH_RECENT_DAYS', 7),

    // Window used for the average daily demand behind days-of-cover and expiry-risk estimates.
    'demand_window_days' => 90,

    // Products with fewer days of cover than this are listed as stockout risks.
    'cover_warning_days' => 14,

    // Expiry-risk buckets in days from today.
    'expiry_buckets' => [30, 90, 180],

    // Dashboards are bounded: longest period a single request may cover.
    'max_period_days' => 731,
];

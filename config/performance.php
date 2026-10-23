<?php

return [
    // Per-request profiling for load tests (App\Http\Middleware\ProfileRequest). Never in production.
    'profile' => (bool) env('PERF_PROFILE', false) && env('APP_ENV') !== 'production',
    // Also record every query's SQL (large output; for investigating one route at a time).
    'profile_sql' => (bool) env('PERF_PROFILE_SQL', false),
];

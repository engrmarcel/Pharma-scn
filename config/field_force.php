<?php

return [
    /*
     * A check-in counts as "verified" only when the device reported a real
     * (non-mock) fix no less precise than max_accuracy_m, the customer has a
     * recorded location, and the fix is within verification_radius_m of it.
     * Every other case is stored as an explicit unverified condition.
     */
    'verification_radius_m' => (int) env('FIELD_FORCE_VERIFICATION_RADIUS_M', 200),
    'max_accuracy_m' => (int) env('FIELD_FORCE_MAX_ACCURACY_M', 100),

    // Device timestamps further in the future than this are refused (clock tampering or a bad clock).
    'max_clock_skew_minutes' => (int) env('FIELD_FORCE_MAX_CLOCK_SKEW_MINUTES', 10),

    // Offline captures older than this are refused rather than silently back-dated.
    'max_offline_age_days' => (int) env('FIELD_FORCE_MAX_OFFLINE_AGE_DAYS', 30),

    // Raw coordinates are removed after this many days (verification results are kept). 0 disables pruning.
    'location_retention_days' => (int) env('FIELD_FORCE_LOCATION_RETENTION_DAYS', 365),
];

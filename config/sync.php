<?php

return [
    // Envelopes per POST /sync/batches request.
    'max_batch' => (int) env('SYNC_MAX_BATCH', 50),

    /*
     * Device tokens expire after this many hours without contact (sliding).
     * After a longer offline period the representative or cashier signs in
     * again; unsent work stays on the device and is sent afterwards.
     */
    'device_token_ttl_hours' => (int) env('SYNC_DEVICE_TOKEN_TTL_HOURS', 72),

    // A stored envelope that keeps failing for unexpected (non-business) reasons is rejected after this many tries.
    'max_processing_attempts' => (int) env('SYNC_MAX_PROCESSING_ATTEMPTS', 5),

    // Envelope type => accepted payload schema versions.
    'types' => [
        'field.visit.check_in' => [1],
        'field.visit.check_out' => [1],
        'field.visit.cancel' => [1],
        'field.order.capture' => [1],
        'field.order.resubmit' => [1],
        'field.follow_up.complete' => [1],
        'crm.detailing.record' => [1],
        'crm.feedback.record' => [1],
        'crm.interaction.log' => [1],
        'pos.sale' => [1],
    ],
];

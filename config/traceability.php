<?php

/*
|--------------------------------------------------------------------------
| Traceability, QR and recall (spec §16, Phase 7)
|--------------------------------------------------------------------------
*/

return [
    // QR codes encode "<scan_base_url>/<opaque token>" and nothing else.
    'scan_base_url' => env('TRACE_SCAN_BASE_URL', rtrim((string) env('APP_URL', 'http://localhost'), '/').'/t'),

    // Countries whose rules prohibit a second two-dimensional code on a pack used for identification
    // or authenticity checks (Nigeria: NAFDAC Pharmaceutical Products (Traceability) Regulations 2024,
    // reg. 7(1)). Platform QR codes issued there carry a warning: use them on documents, shelf or bin
    // labels, not on the pack; on packs, the GS1 DataMatrix is scanned instead.
    'single_2d_code_countries' => ['NG'],

    // Public scans per minute per client.
    'public_scan_rate_per_minute' => 60,

    // Serials registered per request.
    'max_serials_per_request' => 1000,

    'disclaimer' => 'A scan shows what participants have recorded on the platform about this product and batch. It does not by itself prove that the pack is authentic. If in doubt, do not use the product and report it to the supplier or the regulator.',
];

<?php

/*
|--------------------------------------------------------------------------
| Traceability, QR and recall (spec §16, Phase 7)
|--------------------------------------------------------------------------
*/

return [
    // QR codes encode "<scan_base_url>/<opaque token>" and nothing else.
    'scan_base_url' => env('TRACE_SCAN_BASE_URL', rtrim((string) env('APP_URL', 'http://localhost'), '/').'/t'),

    // Whether a pack may carry only one 2D code is a country rule: regulatory.single_2d_code_on_packs
    // in the country profile (Nigeria: NAFDAC Traceability Regulations 2024, reg. 7(1)).

    // Public scans per minute per client.
    'public_scan_rate_per_minute' => 60,

    // Serials registered per request.
    'max_serials_per_request' => 1000,

    'disclaimer' => 'A scan shows what participants have recorded on the platform about this product and batch. It does not by itself prove that the pack is authentic. If in doubt, do not use the product and report it to the supplier or the regulator.',
];

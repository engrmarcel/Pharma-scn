<?php

return [
    /*
     * Who may buy from whom (supplier organization type => buyer types). Mirrors the
     * licensed pharmaceutical supply chain; a listing can narrow it further but never
     * widen it. Individuals/consumers are not organizations on this platform at all.
     */
    'buyer_types_by_supplier_type' => [
        'manufacturer' => ['importer', 'national_distributor', 'regional_distributor', 'wholesaler', 'pharmacy_chain', 'hospital', 'institutional_procurement'],
        'importer' => ['national_distributor', 'regional_distributor', 'wholesaler', 'pharmacy', 'pharmacy_chain', 'hospital', 'healthcare_facility', 'institutional_procurement'],
        'national_distributor' => ['regional_distributor', 'wholesaler', 'pharmacy', 'pharmacy_chain', 'hospital', 'healthcare_facility', 'institutional_procurement'],
        'regional_distributor' => ['wholesaler', 'pharmacy', 'pharmacy_chain', 'hospital', 'healthcare_facility', 'institutional_procurement'],
        'wholesaler' => ['pharmacy', 'pharmacy_chain', 'hospital', 'healthcare_facility', 'institutional_procurement'],
    ],

    /*
     * Products classified like this (in the supplier's country) are restricted: both
     * parties must have passed platform verification, and the buyer must be one of
     * the licensed types below. Platform verification is the platform's own review,
     * not a regulator's approval; it does not replace the licences the law requires.
     */
    'restricted_classifications' => ['prescription_only', 'controlled'],
    'restricted_buyer_types' => [
        'prescription_only' => ['importer', 'national_distributor', 'regional_distributor', 'wholesaler', 'pharmacy', 'pharmacy_chain', 'hospital', 'healthcare_facility', 'institutional_procurement'],
        'controlled' => ['national_distributor', 'regional_distributor', 'wholesaler', 'pharmacy', 'pharmacy_chain', 'hospital'],
    ],

    // A declared availability older than this is shown as stale.
    'declared_availability_stale_after_days' => (int) env('MARKETPLACE_DECLARED_STALE_DAYS', 7),

    // Platform transaction fee charged to the supplier on invoiced marketplace value (Phase 4c).
    'fee_percent' => env('MARKETPLACE_FEE_PERCENT', '1.5000'),
    'fee_minimum' => env('MARKETPLACE_FEE_MINIMUM', '0.0000'),
];

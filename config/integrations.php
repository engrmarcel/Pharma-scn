<?php

/*
|--------------------------------------------------------------------------
| Enterprise integration gateway (spec §15, Phase 5)
|--------------------------------------------------------------------------
*/

return [

    'system_types' => [
        'sap_s4hana' => 'SAP S/4HANA',
        'sap_ecc' => 'SAP ECC',
        'sap_business_one' => 'SAP Business One',
        'oracle_ebs' => 'Oracle E-Business Suite',
        'oracle_fusion' => 'Oracle Fusion Cloud ERP',
        'dynamics_365' => 'Microsoft Dynamics 365',
        'odoo' => 'Odoo',
        'sage' => 'Sage',
        'custom' => 'Custom / in-house system',
        'other' => 'Other',
    ],

    // How records reach the gateway. All of them land in the same durable inbox.
    'inbound_transports' => ['rest_push', 'file_upload', 'sftp', 'sap_idoc'],

    // Records per intake call or file.
    'max_records_per_request' => 500,

    // Processing attempts for unexpected (temporary) errors before a message is dead-lettered as "failed".
    'max_attempts' => 5,
    // Minutes to wait before attempt n+1 (the last value repeats).
    'retry_backoff_minutes' => [1, 5, 15, 60],
    // A message stuck in "processing" this long (worker crash) is picked up again.
    'stale_processing_minutes' => 15,

    // Files (upload, push, SFTP). Larger extracts are split by the source system.
    'files' => [
        'max_bytes' => 20 * 1024 * 1024,
        'max_records' => 10000,
    ],

    // Signed requests: X-Timestamp must be within this many seconds of server time.
    'signature_tolerance_seconds' => 300,

    // Keys whose values are masked wherever a payload or error is shown (case-insensitive, matched on the key name).
    'redact_keys' => [
        'password', 'passwd', 'pwd', 'secret', 'token', 'access_token', 'refresh_token', 'api_key', 'apikey', 'authorization',
        'private_key', 'credentials', 'credential', 'client_secret', 'card_number', 'pan', 'cvv', 'iban', 'account_number',
        'bvn', 'nin', 'patient_name', 'patient_id', 'date_of_birth', 'phone', 'email',
    ],

    // Health thresholds per connector.
    'health' => [
        'backlog_warning' => 100,          // received but not yet processed
        'oldest_pending_warning_minutes' => 30,
    ],
];

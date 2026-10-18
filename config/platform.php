<?php

/*
|--------------------------------------------------------------------------
| Platform configuration
|--------------------------------------------------------------------------
|
| The product's final brand is not settled. Nothing user-facing may
| hard-code a brand name: everything reads from BrandingService, which
| layers administrator-saved settings (platform_settings table) over the
| defaults below. `code` is the stable technical identifier.
|
*/

return [

    'code' => 'pharma_network',

    'branding' => [
        'display_name' => env('PLATFORM_DISPLAY_NAME', 'Pharma Network'),
        'logo_url' => env('PLATFORM_LOGO_URL'),
        'favicon_url' => env('PLATFORM_FAVICON_URL'),
        'primary_color' => env('PLATFORM_PRIMARY_COLOR', '#0b4ea2'),
        'secondary_color' => env('PLATFORM_SECONDARY_COLOR', '#0b1f42'),
        'email_from_name' => env('PLATFORM_EMAIL_FROM_NAME', env('MAIL_FROM_NAME', 'Pharma Network')),
        'email_from_address' => env('PLATFORM_EMAIL_FROM_ADDRESS', env('MAIL_FROM_ADDRESS', 'no-reply@example.com')),
        'support_email' => env('PLATFORM_SUPPORT_EMAIL', 'support@example.com'),
        'support_phone' => env('PLATFORM_SUPPORT_PHONE'),
        'website_url' => env('PLATFORM_WEBSITE_URL', env('APP_URL')),
    ],

    // Keys an organization may override for its own documents/receipts.
    'tenant_brandable_keys' => ['display_name', 'logo_url', 'primary_color', 'secondary_color', 'support_email', 'support_phone'],

    'tenancy' => [
        // API clients select the acting organization with this header.
        'header' => 'X-Organization-Id',
        'session_key' => 'current_organization_id',
    ],

    'idempotency' => [
        'header' => 'Idempotency-Key',
        'ttl_hours' => 48,
    ],

    'attachments' => [
        'disk' => env('ATTACHMENTS_DISK', 'local'),
        'max_kb' => 10240,
        'allowed_mimes' => ['pdf', 'jpg', 'jpeg', 'png', 'webp'],
    ],

    /*
    | Business modules an organization can have enabled. Defaults by
    | organization type are applied at onboarding; platform operations can
    | change them later (entitlements will gate them once billing ships).
    */
    'modules' => [
        'catalogue' => 'Product catalogue',
        'inventory' => 'Inventory & warehouse',
        'procurement' => 'Procurement',
        'sales' => 'Sales & invoicing',
        'finance' => 'Finance',
        'pos' => 'Point of sale',
        'field_force' => 'Field force',
        'marketplace' => 'B2B marketplace',
        'traceability' => 'Traceability & recall',
        'logistics' => 'Logistics',
        'integrations' => 'Enterprise integrations',
        'analytics' => 'Analytics',
    ],

    'default_modules' => [
        '*' => ['catalogue'],
        'pharmacy' => ['catalogue', 'inventory', 'procurement', 'sales', 'pos', 'finance'],
        'pharmacy_chain' => ['catalogue', 'inventory', 'procurement', 'sales', 'pos', 'finance'],
        'manufacturer' => ['catalogue', 'inventory', 'sales', 'field_force', 'traceability', 'integrations', 'finance'],
        'importer' => ['catalogue', 'inventory', 'procurement', 'sales', 'traceability', 'finance'],
        'national_distributor' => ['catalogue', 'inventory', 'procurement', 'sales', 'field_force', 'traceability', 'finance'],
        'regional_distributor' => ['catalogue', 'inventory', 'procurement', 'sales', 'field_force', 'traceability', 'finance'],
        'wholesaler' => ['catalogue', 'inventory', 'procurement', 'sales', 'finance'],
        'hospital' => ['catalogue', 'inventory', 'procurement', 'finance'],
        'healthcare_facility' => ['catalogue', 'inventory', 'procurement', 'finance'],
        'logistics_provider' => ['logistics'],
        'cold_chain_logistics_provider' => ['logistics'],
    ],
];

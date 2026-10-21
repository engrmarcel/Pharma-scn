<?php

/*
|--------------------------------------------------------------------------
| Entitlement registry (spec §21.2)
|--------------------------------------------------------------------------
|
| type "limit"   - numeric allowance; NULL limit = unlimited.
| type "feature" - boolean.
| Module entitlements ("module.<code>") gate the business modules in
| config/platform.php on top of the per-organization module switch.
|
| Plans and their values live in the database (seeded by
| SubscriptionPlanSeeder, then edited by platform billing staff); these
| keys are the vocabulary the backend enforces.
|
*/

return [
    'keys' => [
        'users' => ['limit', 'Active members plus pending invitations'],
        'branches' => ['limit', 'Branches'],
        'warehouses' => ['limit', 'Warehouses'],
        'pos_terminals' => ['limit', 'POS terminals (enforced from Phase 2)'],
        'mobile_users' => ['limit', 'Field-force mobile users (enforced from Phase 2)'],
        'integration_connectors' => ['limit', 'Enterprise integration connectors (not disabled)'],
        'monthly_transactions' => ['limit', 'Monthly transaction allowance (enforced from Phase 2)'],
        'storage_mb' => ['limit', 'Document storage in MB'],
        'api_requests_per_minute' => ['limit', 'API rate limit per user'],
        'advanced_reporting' => ['feature', 'Advanced reporting'],
        'ai_features' => ['feature', 'AI recommendations and assistant'],
        'module.catalogue' => ['feature', 'Product catalogue module'],
        'module.inventory' => ['feature', 'Inventory module'],
        'module.procurement' => ['feature', 'Procurement module'],
        'module.sales' => ['feature', 'Sales module'],
        'module.finance' => ['feature', 'Finance module'],
        'module.pos' => ['feature', 'POS module'],
        'module.field_force' => ['feature', 'Field-force module'],
        'module.marketplace' => ['feature', 'Marketplace module'],
        'module.traceability' => ['feature', 'Traceability module'],
        'module.logistics' => ['feature', 'Logistics module'],
        'module.integrations' => ['feature', 'Integrations module'],
        'module.analytics' => ['feature', 'Analytics module'],
    ],

    // Plan assigned at onboarding.
    'default_plan' => 'trial',

    // Subscription states in which the organization may add new users, locations etc.
    // In any other state existing records stay readable; nothing is deleted or hidden.
    'operational_statuses' => ['trialing', 'active', 'past_due', 'grace'],
];

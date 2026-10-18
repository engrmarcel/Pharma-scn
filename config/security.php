<?php

/*
|--------------------------------------------------------------------------
| Account security policy
|--------------------------------------------------------------------------
|
| Both controls are OFF by default and can be switched on per environment
| (env) or at runtime by a platform settings administrator
| (PUT /api/v1/admin/security-settings), which overrides these defaults.
|
| mfa_mode:
|   off                          - two-factor is unavailable; nobody is challenged
|   optional                     - users may enrol; enrolled users are always challenged;
|                                  organizations may require it for their members
|   required_for_platform_staff  - as optional, plus mandatory for anyone holding a platform role
|   required_for_all             - mandatory for every user
|
| email_verification_required:
|   false - accounts work without verifying their email address
|   true  - unverified accounts can only verify, view their profile and sign out
|
*/

return [
    'mfa_mode' => env('SECURITY_MFA_MODE', 'off'),

    'email_verification_required' => (bool) env('SECURITY_EMAIL_VERIFICATION_REQUIRED', false),

    'mfa_modes' => ['off', 'optional', 'required_for_platform_staff', 'required_for_all'],

    // Shown in authenticator apps; falls back to the platform display name.
    'mfa_issuer' => env('SECURITY_MFA_ISSUER'),

    'recovery_code_count' => 8,
];

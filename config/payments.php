<?php

use App\Domain\Pos\Payments\FakePaymentProvider;

/*
|--------------------------------------------------------------------------
| Payment providers (spec §11.3)
|--------------------------------------------------------------------------
|
| Electronic payments are only confirmed by a provider's verified callback
| (or by a supervisor's settlement check for standalone card terminals).
| Real provider adapters (e.g. Paystack, Flutterwave, Moniepoint) are added
| by implementing App\Domain\Pos\Payments\PaymentProvider once credentials
| and the provider's documented verification method are available.
|
| Each provider declares the countries and currencies it supports; a country's
| profile (payments.providers) then enables it there (PaymentProviderRegistry).
|
| "fake" is a DEVELOPMENT/TEST provider with HMAC-signed webhooks. It can
| never be enabled in production.
|
*/

return [
    'providers' => [
        'fake' => [
            'enabled' => (bool) env('PAYMENTS_FAKE_ENABLED', env('APP_ENV') !== 'production'),
            'secret' => env('PAYMENTS_FAKE_SECRET', 'dev-only-fake-provider-secret'),
            'class' => FakePaymentProvider::class,
            'name' => 'Development provider (no money moves)',
            'kind' => 'card',          // card | mobile_money | bank_transfer | ussd
            'countries' => ['*'],
            'currencies' => ['*'],
        ],
    ],
];

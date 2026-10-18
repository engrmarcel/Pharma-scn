<?php

namespace App\Domain\Pos\Payments;

use App\Support\Exceptions\BusinessRuleViolation;

class PaymentProviderRegistry
{
    public function get(string $code): PaymentProvider
    {
        $config = config("payments.providers.{$code}");
        $enabled = $config && $config['enabled'] && ! ($code === 'fake' && app()->isProduction());

        if (! $enabled) {
            throw new BusinessRuleViolation('payment_provider_unavailable', "Payment provider '{$code}' is not available.", 404);
        }

        return app($config['class']);
    }
}

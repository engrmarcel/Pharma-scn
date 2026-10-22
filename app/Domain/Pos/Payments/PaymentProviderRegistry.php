<?php

namespace App\Domain\Pos\Payments;

use App\Domain\Platform\Models\Country;
use App\Domain\Platform\Support\CountryProfile;
use App\Support\Exceptions\BusinessRuleViolation;

/**
 * Electronic payment providers (spec §11.3, §20 local payment adapters).
 *
 * Two layers decide where a provider can be used:
 *   - the provider's own configuration: which countries and currencies it supports technically;
 *   - the country profile (payments.providers): which providers the platform has enabled there,
 *     after contracting and testing with them.
 * The development "fake" provider is available everywhere outside production.
 */
class PaymentProviderRegistry
{
    public function get(string $code): PaymentProvider
    {
        $config = config("payments.providers.{$code}");
        if (! $config || ! $this->enabled($code, $config)) {
            throw new BusinessRuleViolation('payment_provider_unavailable', "Payment provider '{$code}' is not available.", 404);
        }

        return app($config['class']);
    }

    /** A provider for a payment in this country and currency. */
    public function forPayment(string $code, string $countryCode, string $currency): PaymentProvider
    {
        if (! array_key_exists($code, $this->availableFor($countryCode, $currency))) {
            throw new BusinessRuleViolation('payment_provider_not_available_here', "Payment provider '{$code}' is not available for {$currency} payments in {$countryCode}.", 422,
                ['available' => array_keys($this->availableFor($countryCode, $currency))]);
        }

        return $this->get($code);
    }

    /** @return array<string, array{name: string, kind: string}> providers usable in this country and currency */
    public function availableFor(string $countryCode, string $currency): array
    {
        $country = Country::query()->find($countryCode);
        $enabledHere = $country ? CountryProfile::of($country)->get('payments.providers', []) : [];
        $out = [];
        foreach (config('payments.providers') as $code => $config) {
            $supports = fn (string $key, string $value) => in_array('*', $config[$key] ?? [], true) || in_array($value, $config[$key] ?? [], true);
            if ($this->enabled($code, $config) && $supports('countries', $countryCode) && $supports('currencies', $currency)
                && ($code === 'fake' || in_array($code, $enabledHere, true))) {
                $out[$code] = ['name' => $config['name'] ?? $code, 'kind' => $config['kind'] ?? 'card'];
            }
        }

        return $out;
    }

    private function enabled(string $code, array $config): bool
    {
        return (bool) $config['enabled'] && ! ($code === 'fake' && app()->isProduction());
    }
}

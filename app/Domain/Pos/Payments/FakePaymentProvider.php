<?php

namespace App\Domain\Pos\Payments;

use App\Domain\Pos\Models\PosPayment;
use Illuminate\Support\Str;

/**
 * DEVELOPMENT/TEST ONLY. Simulates an electronic provider whose webhooks are
 * authenticated with HMAC-SHA256 over the raw body (header X-Fake-Signature).
 * Registry refuses to resolve it in production.
 */
class FakePaymentProvider implements PaymentProvider
{
    public function code(): string
    {
        return 'fake';
    }

    public function initiate(PosPayment $payment, string $currency): array
    {
        return [
            'reference' => 'FAKE-'.strtoupper((string) Str::ulid()),
            'instructions' => ['notice' => 'Development provider: no money moves. Confirm via a signed webhook.'],
        ];
    }

    public function parseWebhook(string $rawBody, array $headers): ProviderEvent
    {
        $signature = $headers['x-fake-signature'][0] ?? $headers['X-Fake-Signature'] ?? null;
        $expected = self::sign($rawBody);
        if (! is_string($signature) || ! hash_equals($expected, $signature)) {
            throw new InvalidWebhook('Signature mismatch.');
        }

        $data = json_decode($rawBody, true);
        if (! is_array($data) || ! isset($data['id'], $data['type'], $data['reference'], $data['amount'], $data['currency'])) {
            throw new InvalidWebhook('Malformed payload.');
        }

        return new ProviderEvent((string) $data['id'], (string) $data['type'], (string) $data['reference'], (string) $data['amount'], (string) $data['currency'], $data);
    }

    public static function sign(string $rawBody): string
    {
        return hash_hmac('sha256', $rawBody, (string) config('payments.providers.fake.secret'));
    }
}

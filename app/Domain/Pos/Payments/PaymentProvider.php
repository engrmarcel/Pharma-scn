<?php

namespace App\Domain\Pos\Payments;

use App\Domain\Pos\Models\PosPayment;

/**
 * Adapter for an electronic payment provider. Implementations must verify
 * callbacks with the provider's documented mechanism (signature or a
 * server-to-server status lookup) — never trust what the client reports.
 */
interface PaymentProvider
{
    public function code(): string;

    /**
     * Start a payment with the provider.
     *
     * @return array{reference: string, instructions: array<string, mixed>}
     */
    public function initiate(PosPayment $payment, string $currency): array;

    /**
     * Verify and parse a webhook. Throws InvalidWebhook when the request is not authentic.
     */
    public function parseWebhook(string $rawBody, array $headers): ProviderEvent;
}

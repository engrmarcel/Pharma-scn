<?php

namespace App\Domain\Pos\Payments;

final class ProviderEvent
{
    /**
     * @param  string  $type  payment.succeeded | payment.failed
     */
    public function __construct(
        public readonly string $eventId,
        public readonly string $type,
        public readonly string $reference,
        public readonly string $amount,
        public readonly string $currency,
        public readonly array $payload,
    ) {}
}

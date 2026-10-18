<?php

namespace App\Domain\Finance\Events;

use App\Support\Events\DomainEvent;

/** Spec §26 PaymentConfirmed. */
class PaymentConfirmed extends DomainEvent
{
    public function __construct(public readonly string $paymentId, public readonly string $organizationId)
    {
        parent::__construct();
    }
}

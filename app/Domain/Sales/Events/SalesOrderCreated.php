<?php

namespace App\Domain\Sales\Events;

use App\Support\Events\DomainEvent;

/** Spec §26 OrderCreated for sales orders. */
class SalesOrderCreated extends DomainEvent
{
    public function __construct(public readonly string $salesOrderId, public readonly string $organizationId)
    {
        parent::__construct();
    }
}

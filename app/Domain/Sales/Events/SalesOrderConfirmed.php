<?php

namespace App\Domain\Sales\Events;

use App\Support\Events\DomainEvent;

/** Spec §26 OrderApproved for sales orders: stock is reserved. */
class SalesOrderConfirmed extends DomainEvent
{
    public function __construct(public readonly string $salesOrderId, public readonly string $organizationId)
    {
        parent::__construct();
    }
}

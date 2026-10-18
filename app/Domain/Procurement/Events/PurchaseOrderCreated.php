<?php

namespace App\Domain\Procurement\Events;

use App\Support\Events\DomainEvent;

/** Spec §26 "OrderCreated" for purchase orders. */
class PurchaseOrderCreated extends DomainEvent
{
    public function __construct(public readonly string $purchaseOrderId, public readonly string $organizationId)
    {
        parent::__construct();
    }
}

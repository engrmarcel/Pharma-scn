<?php

namespace App\Domain\Sales\Events;

use App\Support\Events\DomainEvent;

/** Spec §26 SaleCompleted: an invoice was issued for delivered goods (sales order or POS). */
class SaleCompleted extends DomainEvent
{
    public function __construct(public readonly string $salesInvoiceId, public readonly string $organizationId, public readonly string $source)
    {
        parent::__construct();
    }
}

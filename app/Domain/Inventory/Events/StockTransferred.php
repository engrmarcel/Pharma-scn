<?php

namespace App\Domain\Inventory\Events;

use App\Support\Events\DomainEvent;

class StockTransferred extends DomainEvent
{
    public function __construct(
        public readonly string $postingId,
        public readonly string $organizationId,
        public readonly ?string $referenceType,
        public readonly ?string $referenceId,
    ) {
        parent::__construct();
    }
}

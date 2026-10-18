<?php

namespace App\Domain\Inventory\Events;

use App\Support\Events\DomainEvent;

class BatchCreated extends DomainEvent
{
    public function __construct(
        public readonly string $batchId,
        public readonly string $organizationId,
        public readonly string $productId,
    ) {
        parent::__construct();
    }
}

<?php

namespace App\Domain\Catalogue\Events;

use App\Support\Events\DomainEvent;

class ProductUpdated extends DomainEvent
{
    /** @param list<string> $changedFields */
    public function __construct(public readonly string $productId, public readonly int $version, public readonly array $changedFields)
    {
        parent::__construct();
    }
}

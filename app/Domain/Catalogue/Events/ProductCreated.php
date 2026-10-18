<?php

namespace App\Domain\Catalogue\Events;

use App\Support\Events\DomainEvent;

class ProductCreated extends DomainEvent
{
    public function __construct(public readonly string $productId, public readonly int $version)
    {
        parent::__construct();
    }
}

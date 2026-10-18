<?php

namespace App\Domain\Organizations\Events;

use App\Support\Events\DomainEvent;

class OrganizationRegistered extends DomainEvent
{
    public function __construct(public readonly string $organizationId)
    {
        parent::__construct();
    }
}

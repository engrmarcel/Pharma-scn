<?php

namespace App\Domain\Organizations\Events;

use App\Support\Events\DomainEvent;

class OrganizationVerificationChanged extends DomainEvent
{
    public function __construct(
        public readonly string $organizationId,
        public readonly string $fromStatus,
        public readonly string $toStatus,
    ) {
        parent::__construct();
    }
}

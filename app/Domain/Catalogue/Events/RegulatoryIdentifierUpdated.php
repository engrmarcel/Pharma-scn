<?php

namespace App\Domain\Catalogue\Events;

use App\Support\Events\DomainEvent;

/**
 * change: added | updated | verification_recorded
 */
class RegulatoryIdentifierUpdated extends DomainEvent
{
    public function __construct(
        public readonly string $identifierId,
        public readonly string $subjectType,
        public readonly string $subjectId,
        public readonly string $change,
        public readonly string $verificationStatus,
    ) {
        parent::__construct();
    }
}

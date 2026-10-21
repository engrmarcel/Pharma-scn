<?php

namespace App\Support\Events;

/**
 * Records a domain event durably inside the current transaction (the
 * integration outbox). Bound in AppServiceProvider; the default does nothing.
 */
interface EventRecorder
{
    public function record(DomainEvent $event): void;
}

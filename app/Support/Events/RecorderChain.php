<?php

namespace App\Support\Events;

/** Offers each domain event to several transactional recorders (integration outbox, traceability). */
final class RecorderChain implements EventRecorder
{
    /** @param list<EventRecorder> $recorders */
    public function __construct(private readonly array $recorders) {}

    public function record(DomainEvent $event): void
    {
        foreach ($this->recorders as $recorder) {
            $recorder->record($event);
        }
    }
}

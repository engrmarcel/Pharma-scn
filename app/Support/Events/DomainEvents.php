<?php

namespace App\Support\Events;

/**
 * Raises a domain event. Use instead of event() for DomainEvent subclasses.
 *
 * The event is first offered to the EventRecorder (the integration outbox)
 * while the caller's transaction is still open, so an outbound event exists
 * exactly when the business change it describes is committed: never lost after
 * a commit, never sent for a change that rolled back. In-process listeners
 * still run after commit (DomainEvent is ShouldDispatchAfterCommit).
 */
final class DomainEvents
{
    public static function raise(DomainEvent $event): void
    {
        app(EventRecorder::class)->record($event);
        event($event);
    }
}

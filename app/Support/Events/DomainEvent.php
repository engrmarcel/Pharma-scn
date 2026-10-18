<?php

namespace App\Support\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Facades\Context;

/**
 * Base for internal domain events (spec §26). Dispatched only after the
 * surrounding transaction commits, so a failing listener (notification,
 * analytics) can never roll back the business change.
 *
 * Events carry identifiers, not models, so queued listeners re-read
 * current state and payloads never leak tenant data into queues or logs.
 *
 * These are framework-internal events. Durable cross-system integration
 * events go through the integration outbox (Phase 5), which a listener on
 * these events will populate.
 */
abstract class DomainEvent implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public readonly string $occurredAt;

    public readonly ?string $correlationId;

    public function __construct()
    {
        $this->occurredAt = now()->toIso8601String();
        $this->correlationId = Context::get('correlation_id');
    }
}

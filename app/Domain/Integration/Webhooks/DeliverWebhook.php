<?php

namespace App\Domain\Integration\Webhooks;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sends one webhook delivery. Retries are scheduled on the delivery row
 * (with backoff) and picked up by integration:process-due, so the job itself
 * runs once; duplicates do nothing because the dispatcher claims the row.
 */
class DeliverWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $deliveryId)
    {
        $this->afterCommit();
    }

    public function handle(WebhookDispatcher $dispatcher): void
    {
        $dispatcher->deliver($this->deliveryId);
    }
}

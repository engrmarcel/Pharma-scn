<?php

namespace App\Domain\Integration\Jobs;

use App\Domain\Integration\Services\IntegrationProcessor;
use App\Jobs\Middleware\TenantAware;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Processes one integration inbox message in the organization's context.
 * Safe to run more than once: the processor claims the message first, so
 * duplicates of this job (a retry, the scheduler's sweep) do nothing.
 */
class ProcessIntegrationMessage implements ShouldQueue
{
    use Queueable, TenantAware;

    public int $tries = 1; // retries are scheduled by the processor, with backoff, on the message itself

    public function __construct(public readonly int $messageId)
    {
        $this->captureTenant();
        $this->afterCommit();
    }

    public function handle(IntegrationProcessor $processor): void
    {
        $processor->process($this->messageId);
    }
}

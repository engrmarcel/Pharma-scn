<?php

namespace App\Jobs;

use App\Domain\Platform\Services\OperationsMonitor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Dispatched every minute by ops:heartbeat; when a worker runs it, that queue is known to be alive. */
class QueueHeartbeat implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $queueName)
    {
        $this->onQueue($queueName);
    }

    public function handle(OperationsMonitor $monitor): void
    {
        $monitor->beat("queue:{$this->queueName}");
    }
}

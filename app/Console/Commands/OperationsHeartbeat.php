<?php

namespace App\Console\Commands;

use App\Domain\Platform\Services\OperationsMonitor;
use App\Jobs\QueueHeartbeat;
use Illuminate\Console\Command;

/** Run by the scheduler every minute: records that the scheduler is alive and sends a heartbeat job through each watched queue. */
class OperationsHeartbeat extends Command
{
    protected $signature = 'ops:heartbeat';

    protected $description = 'Record the scheduler heartbeat and send a heartbeat job through each monitored queue';

    public function handle(OperationsMonitor $monitor): int
    {
        $monitor->beat(OperationsMonitor::SCHEDULER);
        foreach (config('operations.queues') as $queue) {
            QueueHeartbeat::dispatch($queue);
        }

        return self::SUCCESS;
    }
}

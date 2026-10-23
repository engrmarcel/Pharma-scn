<?php

namespace App\Console\Commands;

use App\Domain\Platform\Services\OperationsMonitor;
use Illuminate\Console\Command;

/** Run by the scheduler every minute: compares operational figures with thresholds and opens or resolves alerts. */
class OperationsMonitorCommand extends Command
{
    protected $signature = 'ops:monitor {--dry-run : Show the checks without opening or resolving alerts}';

    protected $description = 'Check queues, failures, heartbeats and integration backlogs against thresholds';

    public function handle(OperationsMonitor $monitor): int
    {
        if ($this->option('dry-run')) {
            $this->table(['Check', 'Subject', 'Value', 'Level', 'Message'], collect($monitor->evaluate())->map(fn ($r) => [
                $r['check'], $r['subject'], $r['value'] === PHP_INT_MAX ? 'never' : $r['value'], $r['severity'] ?? 'ok', $r['message'],
            ])->all());

            return self::SUCCESS;
        }

        $result = $monitor->run();
        $this->line("Alerts opened: {$result['opened']}, resolved: {$result['resolved']}, open now: {$result['open']}");

        return self::SUCCESS;
    }
}

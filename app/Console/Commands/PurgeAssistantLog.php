<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Removes reporting-assistant log entries older than analytics.assistant.log_retention_days. */
class PurgeAssistantLog extends Command
{
    protected $signature = 'analytics:purge-assistant-log';

    protected $description = 'Delete reporting-assistant question logs past their retention period';

    public function handle(): int
    {
        $deleted = DB::table('analytics_assistant_queries')->where('created_at', '<', now()->subDays(config('analytics.assistant.log_retention_days')))->delete();
        $this->line("Deleted {$deleted} entries.");

        return self::SUCCESS;
    }
}

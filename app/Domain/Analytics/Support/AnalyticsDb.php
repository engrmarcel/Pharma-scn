<?php

namespace App\Domain\Analytics\Support;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The read path for analytics. Every dashboard query goes through here, so pointing
 * ANALYTICS_DB_CONNECTION at a read replica moves the analytical load off the primary.
 * Queries built here bypass the Eloquent tenant scope: each one filters
 * organization_id explicitly (AnalyticsScope supplies it).
 */
final class AnalyticsDb
{
    public static function connection(): ConnectionInterface
    {
        return DB::connection(config('analytics.connection') ?: null);
    }

    public static function table(string $table): Builder
    {
        return self::connection()->table($table);
    }
}

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-request cost for load tests (Phase 10): wall time, query count and time, repeated
 * queries (the same SQL run more than once, the usual sign of a query per row) and peak
 * memory, one JSON line per request in storage/logs/perf.jsonl.
 *
 * Off unless PERF_PROFILE=true; never enable it in production (it holds every query's SQL in memory).
 */
class ProfileRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('performance.profile') || $request->attributes->get('web_client')) {
            return $next($request);
        }

        $queries = [];
        $origins = [];
        DB::listen(function (QueryExecuted $q) use (&$queries, &$origins) {
            $queries[] = [$q->sql, $q->time];
            // Schema introspection at request time is always a finding: note which application code asked.
            if (str_contains($q->sql, 'information_schema') && count($origins) < 3) {
                $origins[] = collect(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 60))
                    ->filter(fn ($f) => isset($f['file']) && str_contains($f['file'], DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR))
                    ->map(fn ($f) => basename($f['file']).':'.$f['line'])->take(4)->implode(' < ');
            }
        });
        $start = hrtime(true);
        $response = $next($request);
        $ms = (hrtime(true) - $start) / 1e6;

        $counts = array_count_values(array_column($queries, 0));
        arsort($counts);
        $repeated = array_filter($counts, fn ($n) => $n > 1);
        $line = [
            'at' => now()->toIso8601String(),
            'method' => $request->method(),
            'route' => $request->route()?->getName() ?? $request->path(),
            'status' => $response->getStatusCode(),
            'ms' => round($ms, 1),
            'queries' => count($queries),
            'query_ms' => round(array_sum(array_column($queries, 1)), 1),
            'repeated' => array_sum($repeated) - count($repeated),
            'top_repeated' => array_slice(array_map(fn ($sql, $n) => ['n' => $n, 'sql' => mb_substr($sql, 0, 160)], array_keys($repeated), $repeated), 0, 3),
            'slowest' => collect($queries)->sortByDesc(1)->take(3)->map(fn ($q) => ['ms' => round($q[1], 1), 'sql' => mb_substr($q[0], 0, 200)])->values()->all(),
            'schema_queries_from' => $origins,
            'all_sql' => config('performance.profile_sql') ? array_map(fn ($q) => round($q[1], 1).'ms '.mb_substr($q[0], 0, 140), $queries) : null,
            'memory_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
            'instance' => (string) ($request->server('SERVER_PORT') ?? ''),
        ];
        file_put_contents(storage_path('logs/perf.jsonl'), json_encode($line)."\n", FILE_APPEND | LOCK_EX);

        return $response;
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Platform\Models\Country;
use App\Domain\Platform\Services\AuditLogger;
use App\Domain\Platform\Services\OperationsMonitor;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Health checks, failed jobs, users and country configuration for platform staff.
 */
class OperationsController extends Controller
{
    /**
     * Public readiness probe for load balancers. Reports only pass/fail per
     * dependency; error details go to the log, never to the response.
     */
    public function health(): JsonResponse
    {
        $checks = [
            'database' => $this->probe(fn () => DB::select('select 1')),
            'cache' => $this->probe(function () {
                Cache::put('health:probe', 1, 10);
                throw_unless(Cache::get('health:probe') === 1, \RuntimeException::class, 'cache read-back failed');
            }),
            'storage' => $this->probe(function () {
                $disk = Storage::disk(config('platform.attachments.disk'));
                $disk->put('health/probe.txt', 'ok');
                $disk->delete('health/probe.txt');
            }),
        ];
        $healthy = ! in_array(false, $checks, true);

        return response()->json([
            'status' => $healthy ? 'ok' : 'degraded',
            'region' => config('platform.regions.current'),
            'checks' => array_map(fn ($ok) => $ok ? 'ok' : 'fail', $checks),
        ], $healthy ? 200 : 503);
    }

    /** Operational metrics for staff: queue backlog and failures. */
    public function status(OperationsMonitor $monitor): JsonResponse
    {
        Gate::authorize('platform.operations.manage');
        // Through the queue API, so the figures are right for the database and Redis drivers alike.
        $m = $monitor->metrics();
        $queues = collect($m['queues']);

        return response()->json(['data' => [
            'queue' => [
                'pending_jobs' => $queues->sum('pending'),
                'oldest_pending_seconds' => $queues->pluck('oldest_pending_seconds')->filter(fn ($v) => $v !== null)->max(),
                'failed_jobs' => $m['failed_jobs_total'],
                'failed_last_hour' => $m['failed_jobs_last_hour'],
                'by_queue' => $m['queues'],
            ],
            'scheduler_heartbeat_age_seconds' => $m['scheduler_heartbeat_age_seconds'],
            'alerts' => DB::table('operational_alerts')->where('status', 'open')->orderByRaw("CASE severity WHEN 'critical' THEN 0 ELSE 1 END")->orderBy('opened_at')
                ->get(['id', 'check', 'subject', 'severity', 'message', 'value', 'peak_value', 'opened_at', 'last_seen_at']),
            'idempotency_keys' => DB::table('idempotency_keys')->count(),
            'organizations_pending_verification' => DB::table('organizations')->where('verification_status', 'pending_review')->count(),
            'product_change_requests_pending' => DB::table('product_change_requests')->where('status', 'pending')->count(),
            'integrations' => [
                'active_connectors' => DB::table('integration_connectors')->where('status', 'active')->count(),
                'inbox_backlog' => DB::table('integration_inbox')->whereIn('status', ['received', 'processing'])->count(),
                'inbox_quarantined' => DB::table('integration_inbox')->where('status', 'quarantined')->count(),
                'inbox_dead_lettered' => DB::table('integration_inbox')->where('status', 'failed')->count(),
                'webhook_backlog' => DB::table('webhook_deliveries')->whereIn('status', ['pending', 'delivering', 'failed'])->count(),
                'webhook_dead' => DB::table('webhook_deliveries')->where('status', 'dead')->count(),
            ],
        ]]);
    }

    /** Alert history, newest first. */
    public function alerts(Request $request): JsonResponse
    {
        Gate::authorize('platform.operations.manage');
        $status = $request->validate(['status' => ['nullable', 'in:open,resolved']])['status'] ?? null;

        return response()->json(DB::table('operational_alerts')->when($status, fn ($q, $s) => $q->where('status', $s))->orderByDesc('opened_at')->paginate(50));
    }

    /**
     * Prometheus text format for an external scraper (Phase 10b). Authenticated by the static bearer
     * token OPS_METRICS_TOKEN, not a user session; disabled (404) when no token is configured.
     * Platform-level figures only: no tenant data.
     */
    public function metrics(Request $request, OperationsMonitor $monitor): Response
    {
        $token = (string) config('operations.metrics_token');
        abort_if($token === '', 404);
        abort_unless(hash_equals($token, (string) $request->bearerToken()), 401);

        $m = $monitor->metrics();
        $lines = [];
        $gauge = function (string $name, string $help, $value, array $labels = []) use (&$lines) {
            static $declared = [];
            if (! isset($declared[$name])) {
                $lines[] = "# HELP pharma_{$name} {$help}";
                $lines[] = "# TYPE pharma_{$name} gauge";
                $declared[$name] = true;
            }
            $l = $labels === [] ? '' : '{'.collect($labels)->map(fn ($v, $k) => $k.'="'.addcslashes((string) $v, '"\\').'"')->implode(',').'}';
            $lines[] = "pharma_{$name}{$l} ".($value === null ? 'NaN' : $value);
        };
        foreach ($m['queues'] as $queue => $q) {
            $gauge('queue_pending_jobs', 'Jobs waiting in the queue.', $q['pending'], ['queue' => $queue]);
            $gauge('queue_oldest_pending_seconds', 'Age of the oldest waiting job.', $q['oldest_pending_seconds'] ?? 0, ['queue' => $queue]);
            $gauge('queue_heartbeat_age_seconds', 'Seconds since a worker last ran the heartbeat job (NaN: never).', $q['heartbeat_age_seconds'], ['queue' => $queue]);
        }
        foreach (['failed_jobs_total' => 'Failed jobs kept.', 'failed_jobs_last_hour' => 'Jobs failed in the last hour.',
            'scheduler_heartbeat_age_seconds' => 'Seconds since the scheduler last ran (NaN: never).',
            'webhook_deliveries_pending' => 'Webhook deliveries not yet delivered.', 'webhook_deliveries_overdue' => 'Webhook deliveries past their retry time.',
            'webhook_deliveries_dead_last_day' => 'Webhook deliveries abandoned in the last day.', 'integration_inbox_backlog' => 'Integration messages waiting.',
            'integration_inbox_oldest_seconds' => 'Age of the oldest waiting integration message.', 'integration_quarantined' => 'Quarantined integration messages.',
            'integration_failed' => 'Integration messages that failed.', 'sync_inbox_backlog' => 'Offline transactions waiting.',
            'sync_issues_open_high' => 'Open high-severity sync issues.', 'open_alerts' => 'Open operational alerts.'] as $key => $help) {
            $gauge($key, $help, $key === 'integration_inbox_oldest_seconds' ? ($m[$key] ?? 0) : $m[$key]);
        }

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; version=0.0.4']);
    }

    /** Failed jobs without their payloads (they can contain tenant data). */
    public function failedJobs(): JsonResponse
    {
        Gate::authorize('platform.operations.manage');

        return response()->json(['data' => DB::table('failed_jobs')->orderByDesc('failed_at')->limit(100)->get()->map(fn ($job) => [
            'uuid' => $job->uuid,
            'queue' => $job->queue,
            'job' => json_decode($job->payload, true)['displayName'] ?? null,
            'error' => Str::limit(strtok((string) $job->exception, "\n"), 300),
            'failed_at' => $job->failed_at,
        ])]);
    }

    public function retryFailedJob(string $uuid, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('platform.operations.manage');
        abort_unless(DB::table('failed_jobs')->where('uuid', $uuid)->exists(), 404);

        Artisan::call('queue:retry', ['id' => [$uuid]]);
        $audit->record('operations.failed_job_retried', null, metadata: ['uuid' => $uuid], organizationId: false);

        return response()->json(['data' => ['uuid' => $uuid, 'status' => 'requeued']], 202);
    }

    /** User directory for account administration: identity fields only, no tenant business data. */
    public function users(Request $request): JsonResponse
    {
        Gate::authorize('platform.users.manage');
        $q = $request->validate(['q' => ['nullable', 'string', 'max:100']])['q'] ?? null;

        $users = User::query()
            ->when($q, fn ($query) => $query->where(fn ($w) => $w->where('email', 'like', "%{$q}%")->orWhere('name', 'like', "%{$q}%")))
            ->withCount('activeMemberships')
            ->orderBy('id')
            ->paginate(50);

        return response()->json($users->through(fn (User $u) => [
            'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'status' => $u->status,
            'last_login_at' => $u->last_login_at?->toIso8601String(), 'memberships' => $u->active_memberships_count,
        ]));
    }

    private function probe(callable $check): bool
    {
        try {
            $check();

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }
}

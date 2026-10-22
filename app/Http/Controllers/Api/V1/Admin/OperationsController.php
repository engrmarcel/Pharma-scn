<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Platform\Models\Country;
use App\Domain\Platform\Services\AuditLogger;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
            'checks' => array_map(fn ($ok) => $ok ? 'ok' : 'fail', $checks),
        ], $healthy ? 200 : 503);
    }

    /** Operational metrics for staff: queue backlog and failures. */
    public function status(): JsonResponse
    {
        Gate::authorize('platform.operations.manage');

        return response()->json(['data' => [
            'queue' => [
                'pending_jobs' => DB::table('jobs')->count(),
                'oldest_pending_seconds' => ($oldest = DB::table('jobs')->min('created_at')) ? now()->timestamp - (int) $oldest : null,
                'failed_jobs' => DB::table('failed_jobs')->count(),
            ],
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

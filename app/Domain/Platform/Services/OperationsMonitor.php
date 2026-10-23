<?php

namespace App\Domain\Platform\Services;

use App\Models\User;
use App\Notifications\OperationalAlertNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * Platform health in figures, and alerts when a figure crosses its threshold (Phase 10b).
 *
 * Figures are read through the queue API (not the jobs table), so they are right for the
 * database and Redis queue drivers alike. An alert is one problem episode: it opens once,
 * stays open (its value and peak updated) while the check keeps failing, and resolves when the
 * check recovers; operations staff are notified on opening and on resolving, not every minute.
 */
class OperationsMonitor
{
    public const SCHEDULER = 'scheduler';

    /** Record that something is alive (the scheduler, or a queue's workers). */
    public function beat(string $name): void
    {
        DB::table('operational_heartbeats')->upsert([['name' => $name, 'beat_at' => now()]], ['name'], ['beat_at']);
    }

    /** @return array<string, mixed> every monitored figure, with heartbeat ages in seconds (null = never seen) */
    public function metrics(): array
    {
        $now = now();
        $beats = DB::table('operational_heartbeats')->pluck('beat_at', 'name');
        $age = fn (?string $at) => $at === null ? null : (int) abs(Carbon::parse($at)->diffInSeconds($now));

        $queues = [];
        foreach (config('operations.queues') as $queue) {
            $connection = Queue::connection();
            $oldest = method_exists($connection, 'creationTimeOfOldestPendingJob') ? $connection->creationTimeOfOldestPendingJob($queue) : null;
            $queues[$queue] = [
                'pending' => method_exists($connection, 'pendingSize') ? (int) $connection->pendingSize($queue) : (int) $connection->size($queue),
                'oldest_pending_seconds' => $oldest === null ? null : max(0, $now->timestamp - (int) $oldest),
                'heartbeat_age_seconds' => $age($beats["queue:{$queue}"] ?? null),
            ];
        }

        $overdue = $now->copy()->subSeconds((int) config('operations.webhook_overdue_seconds'));

        return [
            'queues' => $queues,
            'failed_jobs_total' => DB::table('failed_jobs')->count(),
            'failed_jobs_last_hour' => DB::table('failed_jobs')->where('failed_at', '>=', $now->copy()->subHour())->count(),
            'scheduler_heartbeat_age_seconds' => $age($beats[self::SCHEDULER] ?? null),
            'webhook_deliveries_pending' => DB::table('webhook_deliveries')->whereIn('status', ['pending', 'delivering', 'failed'])->count(),
            'webhook_deliveries_overdue' => DB::table('webhook_deliveries')->whereIn('status', ['pending', 'failed'])->where('next_attempt_at', '<', $overdue)->count(),
            'webhook_deliveries_dead_last_day' => DB::table('webhook_deliveries')->where('status', 'dead')->where('updated_at', '>=', $now->copy()->subDay())->count(),
            'integration_inbox_backlog' => DB::table('integration_inbox')->whereIn('status', ['received', 'processing'])->count(),
            'integration_inbox_oldest_seconds' => ($t = DB::table('integration_inbox')->whereIn('status', ['received', 'processing'])->min('received_at')) ? $age($t) : null,
            'integration_quarantined' => DB::table('integration_inbox')->where('status', 'quarantined')->count(),
            'integration_failed' => DB::table('integration_inbox')->where('status', 'failed')->count(),
            'sync_inbox_backlog' => DB::table('sync_inbox')->where('status', 'received')->count(),
            'sync_issues_open_high' => DB::table('sync_issues')->where('status', 'open')->where('severity', 'high')->count(),
            'idempotency_keys' => DB::table('idempotency_keys')->count(),
            'open_alerts' => DB::table('operational_alerts')->where('status', 'open')->count(),
            'backup_age_seconds' => $age($beats['backup'] ?? null),
            'restore_drill_age_seconds' => $age($beats['restore_drill'] ?? null),
        ];
    }

    /**
     * Compare the figures with the thresholds.
     *
     * @return list<array{check: string, subject: string, value: int|null, severity: ?string, message: string}>
     */
    public function evaluate(?array $metrics = null): array
    {
        $m = $metrics ?? $this->metrics();
        $results = [];
        $add = function (string $check, string $subject, ?int $value, string $describe) use (&$results) {
            $t = config("operations.thresholds.{$check}");
            $severity = null;
            if ($value !== null) {
                if (($t['critical'] ?? null) !== null && $value >= $t['critical']) {
                    $severity = 'critical';
                } elseif (($t['warning'] ?? null) !== null && $value >= $t['warning']) {
                    $severity = 'warning';
                }
            }
            $results[] = ['check' => $check, 'subject' => $subject, 'value' => $value, 'severity' => $severity, 'message' => $describe];
        };

        foreach ($m['queues'] as $queue => $q) {
            $add('queue_pending_jobs', $queue, $q['pending'], "Queue {$queue}: {$q['pending']} jobs waiting.");
            $add('queue_oldest_pending_seconds', $queue, $q['oldest_pending_seconds'], $q['oldest_pending_seconds'] === null ? "Queue {$queue}: nothing is waiting." : "Queue {$queue}: the oldest job has waited ".$this->duration($q['oldest_pending_seconds']).'.');
            // No heartbeat ever is reported as stale too: workers that never ran are a problem.
            $add('queue_heartbeat_age_seconds', $queue, $q['heartbeat_age_seconds'] ?? PHP_INT_MAX,
                $q['heartbeat_age_seconds'] === null ? "Queue {$queue}: no worker has processed the heartbeat job yet." : "Queue {$queue}: workers last processed the heartbeat job ".$this->duration($q['heartbeat_age_seconds']).' ago.');
        }
        $add('scheduler_heartbeat_age_seconds', '', $m['scheduler_heartbeat_age_seconds'] ?? PHP_INT_MAX,
            $m['scheduler_heartbeat_age_seconds'] === null ? 'The scheduler has never run.' : 'The scheduler last ran '.$this->duration($m['scheduler_heartbeat_age_seconds']).' ago.');
        $add('failed_jobs_last_hour', '', $m['failed_jobs_last_hour'], "{$m['failed_jobs_last_hour']} jobs failed in the last hour ({$m['failed_jobs_total']} failed jobs kept).");
        $add('webhook_deliveries_overdue', '', $m['webhook_deliveries_overdue'], "{$m['webhook_deliveries_overdue']} webhook deliveries are overdue.");
        $add('webhook_deliveries_dead_last_day', '', $m['webhook_deliveries_dead_last_day'], "{$m['webhook_deliveries_dead_last_day']} webhook deliveries gave up in the last day.");
        $add('integration_inbox_backlog', '', $m['integration_inbox_backlog'], "{$m['integration_inbox_backlog']} integration messages are waiting to be processed.");
        $add('integration_inbox_oldest_seconds', '', $m['integration_inbox_oldest_seconds'], $m['integration_inbox_oldest_seconds'] === null ? 'No integration message is waiting.' : 'The oldest waiting integration message arrived '.$this->duration($m['integration_inbox_oldest_seconds']).' ago.');
        $add('integration_quarantined', '', $m['integration_quarantined'], "{$m['integration_quarantined']} integration messages are quarantined.");
        $add('sync_inbox_backlog', '', $m['sync_inbox_backlog'], "{$m['sync_inbox_backlog']} offline transactions are waiting to be processed.");
        $add('sync_issues_open_high', '', $m['sync_issues_open_high'], "{$m['sync_issues_open_high']} high-severity sync issues are open.");

        // Backups and restore drills, when this deployment runs them (backup.enabled).
        if (config('backup.enabled')) {
            $hours = $m['backup_age_seconds'] === null ? PHP_INT_MAX : intdiv($m['backup_age_seconds'], 3600);
            $days = $m['restore_drill_age_seconds'] === null ? PHP_INT_MAX : intdiv($m['restore_drill_age_seconds'], 86400);
            $results[] = $this->level('backup_age', $hours, config('backup.backup_max_age_hours'),
                $hours === PHP_INT_MAX ? 'No database backup has completed yet.' : "The last successful database backup is {$hours} h old.");
            $results[] = $this->level('restore_drill_age', $days, config('backup.drill_max_age_days'),
                $days === PHP_INT_MAX ? 'No restore drill has passed yet.' : "The last successful restore drill was {$days} days ago.");
        }

        return $results;
    }

    /**
     * Open, update and resolve alerts from an evaluation, and notify operations staff of changes.
     *
     * @return array{opened: int, resolved: int, open: int}
     */
    public function run(): array
    {
        return $this->apply($this->evaluate());
    }

    /**
     * Report the outcome of a check made elsewhere (for example the nightly consistency check),
     * with the same open-once, resolve-on-recovery behaviour. A null severity means the check passed.
     */
    public function report(string $check, string $subject, ?string $severity, string $message, ?int $value = null): void
    {
        $this->apply([['check' => $check, 'subject' => $subject, 'value' => $value, 'severity' => $severity, 'message' => $message]]);
    }

    /** @param  list<array{check: string, subject: string, value: int|null, severity: ?string, message: string}>  $results */
    private function apply(array $results): array
    {
        $now = now();
        $opened = $resolved = 0;
        $changed = [];

        foreach ($results as $r) {
            $open = DB::table('operational_alerts')->where('status', 'open')->where('check', $r['check'])->where('subject', $r['subject'])->first();
            $value = $r['value'] === PHP_INT_MAX ? null : $r['value'];
            if ($r['severity'] !== null) {
                if ($open === null) {
                    $id = strtolower((string) Str::ulid());
                    DB::table('operational_alerts')->insert(['id' => $id, 'check' => $r['check'], 'subject' => $r['subject'], 'severity' => $r['severity'], 'status' => 'open',
                        'message' => $r['message'], 'value' => $value, 'peak_value' => $value, 'opened_at' => $now, 'last_seen_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
                    $changed[] = ['opened', $id];
                    $opened++;
                } else {
                    $escalated = $open->severity === 'warning' && $r['severity'] === 'critical';
                    DB::table('operational_alerts')->where('id', $open->id)->update(['severity' => $escalated ? 'critical' : $open->severity, 'message' => $r['message'], 'value' => $value,
                        'peak_value' => $value === null ? $open->peak_value : max((float) $open->peak_value, $value), 'last_seen_at' => $now, 'updated_at' => $now]);
                    if ($escalated) {
                        $changed[] = ['escalated', $open->id];
                    }
                }
            } elseif ($open !== null) {
                DB::table('operational_alerts')->where('id', $open->id)->update(['status' => 'resolved', 'resolved_at' => $now, 'value' => $value, 'updated_at' => $now]);
                $changed[] = ['resolved', $open->id];
                $resolved++;
            }
        }

        foreach ($changed as [$event, $id]) {
            $alert = DB::table('operational_alerts')->where('id', $id)->first();
            Log::log($event === 'resolved' ? 'info' : ($alert->severity === 'critical' ? 'critical' : 'warning'),
                "Operational alert {$event}: {$alert->message}", ['check' => $alert->check, 'subject' => $alert->subject, 'alert_id' => $id]);
            $recipients = $this->recipients();
            if ($recipients->isNotEmpty()) {
                Notification::send($recipients, new OperationalAlertNotification($event, (array) $alert));
            }
            DB::table('operational_alerts')->where('id', $id)->update(['notified_at' => $now]);
        }

        return ['opened' => $opened, 'resolved' => $resolved, 'open' => DB::table('operational_alerts')->where('status', 'open')->count()];
    }

    /** Active platform staff who manage operations. */
    public function recipients()
    {
        return User::query()->where('status', 'active')->whereIn('id', DB::table('platform_role_user')
            ->join('permission_role', 'permission_role.role_id', '=', 'platform_role_user.role_id')
            ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->where('permissions.code', 'platform.operations.manage')->select('platform_role_user.user_id'))->get();
    }

    /** @param  array{warning: ?int, critical: ?int}  $t */
    private function level(string $check, int $value, array $t, string $message): array
    {
        $severity = ($t['critical'] ?? null) !== null && $value >= $t['critical'] ? 'critical' : (($t['warning'] ?? null) !== null && $value >= $t['warning'] ? 'warning' : null);

        return ['check' => $check, 'subject' => '', 'value' => $value, 'severity' => $severity, 'message' => $message];
    }

    private function duration(?int $seconds): string
    {
        if ($seconds === null) {
            return 'no time';
        }

        return $seconds < 120 ? "{$seconds} s" : ($seconds < 7200 ? round($seconds / 60).' min' : round($seconds / 3600, 1).' h');
    }
}

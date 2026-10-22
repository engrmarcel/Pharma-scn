<?php

namespace App\Domain\Analytics\Services;

use App\Domain\Analytics\Support\AnalyticsDb;
use App\Domain\Analytics\Support\Figures;
use App\Domain\Integration\Services\IntegrationMonitor;

/**
 * Integration health, offline synchronization and recall status (spec §19.1).
 * These cover the whole organization, so they need access to all locations.
 */
class OperationsAnalytics
{
    public function __construct(private readonly AnalyticsScope $scope, private readonly IntegrationMonitor $monitor) {}

    public function integrations(string $from, string $to): array
    {
        $this->scope->authorize('integrations.view');
        $this->scope->requireAllLocations('integration');
        [$start, $end] = $this->scope->period($from, $to);
        $org = $this->scope->organizationId();
        $bucket = Figures::bucket($start, $end);
        $messages = fn () => AnalyticsDb::table('integration_inbox')->where('organization_id', $org)->whereBetween('received_at', [$start, $end]);
        $deliveries = AnalyticsDb::table('webhook_deliveries')->where('organization_id', $org)->whereBetween('created_at', [$start, $end])
            ->groupBy('status')->selectRaw('status, COUNT(*) as n')->pluck('n', 'status')->map(fn ($n) => (int) $n);
        $settled = ($deliveries['delivered'] ?? 0) + ($deliveries['dead'] ?? 0);

        return [
            'from' => $from, 'to' => $to, 'trend_bucket' => $bucket,
            'connectors' => collect($this->monitor->health())->map(fn ($c) => array_intersect_key($c, array_flip(['connector_id', 'code', 'name', 'system_type', 'health', 'backlog',
                'oldest_pending_minutes', 'open_quarantined', 'dead_lettered', 'last_processed_at', 'last_failure_at'])))->values(),
            'messages_by_status' => $messages()->groupBy('status')->selectRaw('status, COUNT(*) as n')->pluck('n', 'status')->map(fn ($n) => (int) $n),
            'messages_by_entity' => $messages()->groupBy('entity_type')->selectRaw('entity_type, COUNT(*) as n')->orderByDesc('n')->pluck('n', 'entity_type')->map(fn ($n) => (int) $n),
            'top_errors' => $messages()->whereNotNull('error_code')->groupBy('error_code')->selectRaw('error_code, COUNT(*) as n')->orderByDesc('n')->limit(10)
                ->pluck('n', 'error_code')->map(fn ($n) => (int) $n),
            'trend' => $messages()->groupByRaw(Figures::bucketSql($bucket, 'received_at'))
                ->selectRaw(Figures::bucketSql($bucket, 'received_at')." as period, COUNT(*) as received, SUM(CASE WHEN status IN ('quarantined', 'failed') THEN 1 ELSE 0 END) as problems")
                ->orderBy('period')->get()->map(fn ($r) => ['period' => (string) $r->period, 'received' => (int) $r->received, 'problems' => (int) $r->problems]),
            'webhooks' => ['by_status' => $deliveries, 'success_percent' => Figures::percent($deliveries['delivered'] ?? 0, $settled)],
        ];
    }

    public function sync(string $from, string $to): array
    {
        $this->scope->authorize('sync.reconcile', 'devices.manage');
        $this->scope->requireAllLocations('synchronization');
        [$start, $end] = $this->scope->period($from, $to);
        $org = $this->scope->organizationId();
        $inbox = fn () => AnalyticsDb::table('sync_inbox')->where('organization_id', $org)->whereBetween('received_at', [$start, $end]);
        $lag = $inbox()->where('status', 'synced')->whereNotNull('client_created_at')
            ->selectRaw('AVG(TIMESTAMPDIFF(SECOND, client_created_at, received_at)) as avg_s, MAX(TIMESTAMPDIFF(SECOND, client_created_at, received_at)) as max_s')->first();

        return [
            'from' => $from, 'to' => $to,
            'transactions_by_status' => $inbox()->groupBy('status')->selectRaw('status, COUNT(*) as n')->pluck('n', 'status')->map(fn ($n) => (int) $n),
            'transactions_by_type' => $inbox()->groupBy('type')->selectRaw('type, COUNT(*) as n')->orderByDesc('n')->pluck('n', 'type')->map(fn ($n) => (int) $n),
            'offline_delay_minutes' => ['average' => $lag?->avg_s !== null ? round($lag->avg_s / 60, 1) : null, 'longest' => $lag?->max_s !== null ? round($lag->max_s / 60, 1) : null,
                'basis' => 'Time from capture on the device to arrival on the server, for synchronized transactions.'],
            'open_issues' => AnalyticsDb::table('sync_issues')->where('organization_id', $org)->where('status', 'open')->groupBy('type')
                ->selectRaw('type, COUNT(*) as n')->pluck('n', 'type')->map(fn ($n) => (int) $n),
            'devices' => ['active' => AnalyticsDb::table('devices')->where('organization_id', $org)->where('status', 'active')->count(),
                'not_synced_7_days' => AnalyticsDb::table('devices')->where('organization_id', $org)->where('status', 'active')
                    ->where(fn ($q) => $q->whereNull('last_sync_at')->orWhere('last_sync_at', '<', now()->subDays(7)))->count()],
        ];
    }

    public function recalls(): array
    {
        $this->scope->authorize('recalls.respond', 'recalls.initiate', 'regulatory.incidents.manage');
        $this->scope->requireAllLocations('recall');
        $org = $this->scope->organizationId();

        return [
            'as_of' => now()->toIso8601String(),
            'notices_by_status' => AnalyticsDb::table('recall_notices')->where('organization_id', $org)->groupBy('status')->selectRaw('status, COUNT(*) as n')
                ->pluck('n', 'status')->map(fn ($n) => (int) $n),
            'open_notices' => AnalyticsDb::table('recall_notices as n')->join('recalls as r', 'r.id', '=', 'n.recall_id')->where('n.organization_id', $org)
                ->whereIn('n.status', ['pending', 'acknowledged'])->orderBy('n.notified_at')
                ->get(['n.id', 'r.number', 'r.title', 'r.classification', 'n.status', 'n.notified_at', 'n.held_at_notice']),
            'issued_recalls' => AnalyticsDb::table('recalls')->where('issuer_organization_id', $org)->groupBy('status')->selectRaw('status, COUNT(*) as n')
                ->pluck('n', 'status')->map(fn ($n) => (int) $n),
            'incidents' => ['open' => AnalyticsDb::table('regulatory_incidents')->where('organization_id', $org)->where('status', 'open')->count(),
                'overdue' => AnalyticsDb::table('regulatory_incidents')->where('organization_id', $org)->where('status', 'open')->where('report_due_at', '<', now())->count()],
        ];
    }
}

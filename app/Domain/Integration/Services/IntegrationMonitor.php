<?php

namespace App\Domain\Integration\Services;

use App\Domain\Integration\Models\IntegrationConnector;
use App\Domain\Integration\Models\IntegrationMessage;
use App\Domain\Integration\Models\InventorySnapshot;
use Illuminate\Support\Facades\DB;

/**
 * Integration health (spec §15.4) and data reconciliation reports.
 */
class IntegrationMonitor
{
    /**
     * Per connector: state, counts by status (all time and last 24 hours), backlog and timings.
     *
     *   inactive         draft, paused or disabled
     *   failing          a message was dead-lettered in the last 24 hours, or the latest outcome was a failure
     *   needs_attention  messages are quarantined, or the backlog is large or old
     *   healthy          otherwise
     */
    public function health(): array
    {
        $since = now()->subDay();
        $all = $this->countsBy(IntegrationMessage::query());
        $recent = $this->countsBy(IntegrationMessage::query()->where('received_at', '>=', $since));
        $recentFailed = IntegrationMessage::query()->where('status', 'failed')->where('processed_at', '>=', $since)
            ->groupBy('connector_id')->selectRaw('connector_id, count(*) as n')->pluck('n', 'connector_id');
        $oldest = IntegrationMessage::query()->whereIn('status', ['received', 'processing'])
            ->groupBy('connector_id')->selectRaw('connector_id, min(received_at) as oldest')->pluck('oldest', 'connector_id');

        return IntegrationConnector::query()->orderBy('name')->get()->map(function (IntegrationConnector $c) use ($all, $recent, $recentFailed, $oldest) {
            $counts = $all[$c->id] ?? [];
            $backlog = ($counts['received'] ?? 0) + ($counts['processing'] ?? 0);
            $oldestMinutes = isset($oldest[$c->id]) ? (int) floor(now()->diffInMinutes($oldest[$c->id], true)) : null;
            $lastFailedAfterSuccess = $c->last_failure_at !== null && ($c->last_processed_at === null || $c->last_failure_at->greaterThan($c->last_processed_at));

            $state = match (true) {
                $c->status !== 'active' => 'inactive',
                $c->last_error !== null => 'failing',
                ($recentFailed[$c->id] ?? 0) > 0 || ($lastFailedAfterSuccess && ($counts['failed'] ?? 0) > 0) => 'failing',
                ($counts['quarantined'] ?? 0) > 0 || $backlog > config('integrations.health.backlog_warning')
                    || ($oldestMinutes ?? 0) > config('integrations.health.oldest_pending_warning_minutes') => 'needs_attention',
                default => 'healthy',
            };

            return [
                'connector_id' => $c->id,
                'code' => $c->code,
                'name' => $c->name,
                'system_type' => $c->system_type,
                'status' => $c->status,
                'health' => $state,
                'backlog' => $backlog,
                'oldest_pending_minutes' => $oldestMinutes,
                'open_quarantined' => $counts['quarantined'] ?? 0,
                'dead_lettered' => $counts['failed'] ?? 0,
                'totals' => $this->shape($counts),
                'last_24h' => $this->shape($recent[$c->id] ?? []),
                'last_message_at' => $c->last_message_at?->toIso8601String(),
                'last_processed_at' => $c->last_processed_at?->toIso8601String(),
                'last_failure_at' => $c->last_failure_at?->toIso8601String(),
                'last_error' => $c->last_error,
                'last_transfer_at' => $c->last_transfer_at?->toIso8601String(),
            ];
        })->all();
    }

    /**
     * Source-system stock (latest snapshots) against platform stock, per warehouse,
     * product and batch. Covers only warehouses the connector has reported on.
     */
    public function inventoryReconciliation(IntegrationConnector $connector): array
    {
        $snapshots = InventorySnapshot::query()->where('connector_id', $connector->id)->get();
        $warehouses = $snapshots->pluck('warehouse_id')->unique()->values();

        // Platform stock in all states (available, quarantined, in transit…) per warehouse, product and batch.
        $platform = DB::table('inventory_balances as b')->join('batches as t', 't.id', '=', 'b.batch_id')
            ->where('b.organization_id', $connector->organization_id)->whereIn('b.warehouse_id', $warehouses)
            ->groupBy('b.warehouse_id', 't.product_id', 't.batch_number')
            ->selectRaw('b.warehouse_id, t.product_id, t.batch_number, sum(b.quantity) as quantity')
            ->get();

        $byBatch = [];
        $byProduct = [];
        foreach ($platform as $row) {
            $byBatch["{$row->warehouse_id}|{$row->product_id}|{$row->batch_number}"] = (string) $row->quantity;
            $key = "{$row->warehouse_id}|{$row->product_id}";
            $byProduct[$key] = bcadd($byProduct[$key] ?? '0', (string) $row->quantity, 4);
        }

        $rows = [];
        $seen = [];
        foreach ($snapshots as $s) {
            $key = $s->batch_number === '' ? "{$s->warehouse_id}|{$s->product_id}" : "{$s->warehouse_id}|{$s->product_id}|{$s->batch_number}";
            $ours = $s->batch_number === '' ? ($byProduct[$key] ?? '0') : ($byBatch[$key] ?? '0');
            $seen[$key] = true;
            if ($s->batch_number === '') {
                foreach (array_keys($byBatch) as $batchKey) {
                    if (str_starts_with($batchKey, $key.'|')) {
                        $seen[$batchKey] = true;
                    }
                }
            }
            $rows[] = $this->row($s->warehouse_id, $s->product_id, $s->batch_number ?: null, (string) $s->quantity, $ours, $s->as_of->toIso8601String());
        }
        // Stock we hold that the source system did not report at all.
        foreach ($byBatch as $key => $quantity) {
            [$warehouse, $product] = explode('|', $key);
            if (! isset($seen[$key]) && ! isset($seen["{$warehouse}|{$product}"]) && bccomp($quantity, '0', 4) !== 0) {
                $rows[] = $this->row($warehouse, $product, explode('|', $key)[2], null, $quantity, null);
            }
        }

        $summary = array_count_values(array_column($rows, 'status'));

        return ['connector_id' => $connector->id, 'generated_at' => now()->toIso8601String(), 'warehouse_ids' => $warehouses->all(),
            'summary' => ['positions' => count($rows)] + $summary, 'rows' => $rows];
    }

    private function row(string $warehouse, string $product, ?string $batch, ?string $source, string $platform, ?string $asOf): array
    {
        $difference = bcsub($platform, $source ?? '0', 4);

        return [
            'warehouse_id' => $warehouse,
            'product_id' => $product,
            'batch_number' => $batch,
            'source_quantity' => $source,
            'platform_quantity' => $platform,
            'difference' => $difference,
            'source_as_of' => $asOf,
            'status' => match (true) {
                $source === null => 'not_reported_by_source',
                bccomp($platform, '0', 4) === 0 && bccomp($source, '0', 4) !== 0 => 'not_on_platform',
                bccomp($difference, '0', 4) === 0 => 'match',
                default => 'mismatch',
            },
        ];
    }

    /** @return array<string, array<string, int>> connector_id → status → count */
    private function countsBy($query): array
    {
        $out = [];
        foreach ($query->toBase()->groupBy('connector_id', 'status')->selectRaw('connector_id, status, count(*) as n')->get() as $row) {
            $out[$row->connector_id][$row->status] = (int) $row->n;
        }

        return $out;
    }

    private function shape(array $counts): array
    {
        return [
            'received' => array_sum($counts),
            'pending' => ($counts['received'] ?? 0) + ($counts['processing'] ?? 0),
            'processed' => $counts['processed'] ?? 0,
            'quarantined' => $counts['quarantined'] ?? 0,
            'failed' => $counts['failed'] ?? 0,
            'ignored' => $counts['ignored'] ?? 0,
        ];
    }
}

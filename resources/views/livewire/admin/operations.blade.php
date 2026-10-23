<div class="space-y-6" wire:poll.30s>
    @if ($status)
        @php
            $q = $status['queue']; $i = $status['integrations'];
            $ago = fn ($s) => $s === null ? 'never' : \Carbon\CarbonInterval::seconds($s)->cascade()->forHumans(short: true).' ago';
        @endphp
        <x-ui.card title="Alerts" icon="bell-alert" :padded="false">
            <x-slot:actions><span class="text-xs text-slate-500">Checked every minute by <span class="font-mono">ops:monitor</span>; operations staff are e-mailed when an alert opens and when it clears.</span></x-slot:actions>
            <ul class="divide-y divide-slate-100">
                @forelse ($status['alerts'] as $a)
                    <li class="flex items-start gap-3 px-5 py-3 text-sm" wire:key="al-{{ $a['id'] }}">
                        <x-ui.badge :status="$a['severity'] === 'critical' ? 'critical' : 'warning'">{{ $a['severity'] }}</x-ui.badge>
                        <div class="min-w-0 flex-1"><p class="font-medium">{{ $a['message'] }}</p><p class="muted">{{ str_replace('_', ' ', $a['check']) }}{{ $a['subject'] ? ' · '.$a['subject'] : '' }} · open since {{ fmt()->dateTime($a['opened_at']) }}</p></div>
                    </li>
                @empty
                    <li class="flex items-center gap-2 px-5 py-4 text-sm text-emerald-700"><x-heroicon-o-check-circle class="size-5"/> No open alerts.</li>
                @endforelse
            </ul>
        </x-ui.card>
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <x-ui.stat label="Scheduler" :value="$ago($status['scheduler_heartbeat_age_seconds'])" hint="Last run of the scheduler" :tone="($status['scheduler_heartbeat_age_seconds'] ?? PHP_INT_MAX) > 180 ? 'red' : 'green'"/>
            @foreach ($q['by_queue'] ?? [] as $name => $qq)
                <x-ui.stat :label="'Workers: '.$name" :value="$ago($qq['heartbeat_age_seconds'])" hint="Last heartbeat job processed" :tone="($qq['heartbeat_age_seconds'] ?? PHP_INT_MAX) > 300 ? 'red' : 'green'"/>
            @endforeach
        </div>
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <x-ui.stat label="Pending jobs" :value="number_format($q['pending_jobs'])" :hint="$q['oldest_pending_seconds'] !== null ? 'Oldest waiting '.\Carbon\CarbonInterval::seconds($q['oldest_pending_seconds'])->cascade()->forHumans(short: true) : 'Queue is empty'" :tone="($q['oldest_pending_seconds'] ?? 0) > 300 ? 'amber' : 'green'"/>
            <x-ui.stat label="Failed jobs" :value="number_format($q['failed_jobs'])" :hint="($q['failed_last_hour'] ?? 0).' in the last hour'" :tone="($q['failed_last_hour'] ?? 0) ? 'red' : ($q['failed_jobs'] ? 'amber' : 'green')"/>
            <x-ui.stat label="Organizations awaiting verification" :value="number_format($status['organizations_pending_verification'])"/>
            <x-ui.stat label="Catalogue changes awaiting review" :value="number_format($status['product_change_requests_pending'])"/>
            <x-ui.stat label="Active connectors" :value="number_format($i['active_connectors'])"/>
            <x-ui.stat label="Inbox backlog" :value="number_format($i['inbox_backlog'])" :hint="$i['inbox_quarantined'].' quarantined · '.$i['inbox_dead_lettered'].' failed'" :tone="$i['inbox_dead_lettered'] ? 'red' : null"/>
            <x-ui.stat label="Webhook backlog" :value="number_format($i['webhook_backlog'])" :hint="$i['webhook_dead'].' dead'" :tone="$i['webhook_dead'] ? 'red' : null"/>
            <x-ui.stat label="Idempotency keys held" :value="number_format($status['idempotency_keys'])"/>
        </div>
    @endif

    <x-ui.card title="Failed jobs" icon="exclamation-triangle" :padded="false">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Failed</th><th>Job</th><th>Queue</th><th>Error</th><th></th></tr></thead>
                <tbody>
                    @forelse ($failed as $job)
                        <tr wire:key="j-{{ $job['uuid'] }}"><td class="whitespace-nowrap">{{ fmt()->dateTime($job['failed_at']) }}</td><td class="font-mono text-xs">{{ class_basename($job['job'] ?? '—') }}</td><td>{{ $job['queue'] }}</td>
                            <td class="max-w-md text-xs text-rose-700">{{ $job['error'] }}</td>
                            <td class="text-right"><button type="button" class="btn-light btn-sm" wire:click="retry('{{ $job['uuid'] }}')">Retry</button></td></tr>
                    @empty
                        <x-ui.empty colspan="5" icon="check-circle" title="No failed jobs"/>
                    @endforelse
                </tbody>
            </table>
        </div>
        <p class="border-t border-slate-100 px-5 py-3 text-xs text-slate-500">Job payloads are not shown because they can contain tenant data.</p>
    </x-ui.card>
</div>

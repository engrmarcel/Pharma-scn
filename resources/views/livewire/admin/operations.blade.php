<div class="space-y-6" wire:poll.30s>
    @if ($status)
        @php $q = $status['queue']; $i = $status['integrations']; @endphp
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <x-ui.stat label="Pending jobs" :value="number_format($q['pending_jobs'])" :hint="$q['oldest_pending_seconds'] !== null ? 'Oldest waiting '.\Carbon\CarbonInterval::seconds($q['oldest_pending_seconds'])->cascade()->forHumans(short: true) : 'Queue is empty'" :tone="($q['oldest_pending_seconds'] ?? 0) > 300 ? 'amber' : 'green'"/>
            <x-ui.stat label="Failed jobs" :value="number_format($q['failed_jobs'])" :tone="$q['failed_jobs'] ? 'red' : 'green'"/>
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

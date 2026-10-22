<div>
    <x-ui.toolbar>
        <x-ui.field label="Connector" for="connector"><select id="connector" wire:model.live="connector" class="form-select w-56"><option value="">All</option>@foreach ($connectors as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select></x-ui.field>
    </x-ui.toolbar>
    <div class="card overflow-hidden">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Started</th><th>Connector</th><th>Direction</th><th>File or dataset</th><th>Counts</th><th>State</th></tr></thead>
                <tbody>
                    @forelse ($rows as $r)
                        <tr wire:key="r-{{ $r['id'] }}">
                            <td class="whitespace-nowrap">{{ fmt()->dateTime($r['started_at']) }}</td>
                            <td>{{ $connectors[$r['connector_id']] ?? '' }}</td>
                            <td><x-ui.badge :tone="$r['direction'] === 'inbound' ? 'blue' : 'gold'">{{ $r['direction'] }}</x-ui.badge> <span class="text-xs text-slate-500">{{ $r['source'] }}</span></td>
                            <td class="text-xs">{{ $r['file_name'] ?? $r['dataset'] }}</td>
                            <td class="text-xs">@if (isset($r['counts'])){{ collect($r['counts'])->filter()->map(fn ($v, $k) => str_replace('_', ' ', $k).' '.$v)->implode(' · ') }}@else{{ $r['records'] ?? 0 }} records @endif</td>
                            <td><x-ui.badge :status="match ($r['state']) { 'completed' => 'done', 'processing' => 'processing', default => 'failed' }">{{ str_replace('_', ' ', $r['state']) }}</x-ui.badge>@if ($r['error'] ?? null)<span class="block text-xs text-rose-700">{{ $r['error']['message'] }}</span>@endif</td>
                        </tr>
                    @empty
                        <x-ui.empty colspan="6" icon="play" title="No runs yet"/>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

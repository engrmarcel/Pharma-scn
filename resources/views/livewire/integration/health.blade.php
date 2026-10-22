<div wire:poll.60s>
    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @forelse ($rows as $h)
            <div class="card p-5" wire:key="h-{{ $h['connector_id'] }}">
                <div class="flex items-start justify-between gap-2">
                    <div><p class="font-mono text-xs text-gold-600">{{ $h['code'] }}</p><h3 class="font-semibold text-navy-950">{{ $h['name'] }}</h3></div>
                    <x-ui.badge :status="match ($h['health']) { 'healthy' => 'ok', 'needs_attention' => 'warning', 'failing' => 'failed', default => 'inactive' }">{{ str_replace('_', ' ', $h['health']) }}</x-ui.badge>
                </div>
                <dl class="mt-4 grid grid-cols-2 gap-2 text-sm">
                    <div><dt class="text-xs text-slate-400">Backlog</dt><dd class="font-semibold tabular-nums">{{ $h['backlog'] }}</dd></div>
                    <div><dt class="text-xs text-slate-400">Oldest pending</dt><dd class="tabular-nums">{{ $h['oldest_pending_minutes'] !== null ? $h['oldest_pending_minutes'].' min' : '—' }}</dd></div>
                    <div><dt class="text-xs text-slate-400">Quarantined</dt><dd class="tabular-nums {{ $h['open_quarantined'] ? 'text-amber-700' : '' }}">{{ $h['open_quarantined'] }}</dd></div>
                    <div><dt class="text-xs text-slate-400">Failed</dt><dd class="tabular-nums {{ $h['dead_lettered'] ? 'text-rose-700' : '' }}">{{ $h['dead_lettered'] }}</dd></div>
                </dl>
                <p class="mt-3 text-xs text-slate-500">Last 24 h: {{ collect($h['last_24h'])->filter()->map(fn ($v, $k) => str_replace('_', ' ', $k).' '.$v)->implode(' · ') ?: 'no messages' }}</p>
                <p class="text-xs text-slate-500">Last message {{ $h['last_message_at'] ? fmt()->dateTime($h['last_message_at']) : 'never' }}</p>
                @if ($h['last_error'])<p class="mt-2 text-xs text-rose-700">{{ $h['last_error'] }}</p>@endif
                <div class="mt-3 flex gap-3 text-xs"><a href="{{ route('integration.messages', ['connector' => $h['connector_id'], 'status' => 'quarantined']) }}" class="link">Quarantined</a><a href="{{ route('integration.messages', ['connector' => $h['connector_id'], 'status' => 'failed']) }}" class="link">Failed</a></div>
            </div>
        @empty
            <x-ui.card class="md:col-span-2 xl:col-span-3"><x-ui.empty icon="heart" title="No connectors"/></x-ui.card>
        @endforelse
    </div>
    <p class="mt-3 text-xs text-slate-500">Refreshes every minute.</p>
</div>

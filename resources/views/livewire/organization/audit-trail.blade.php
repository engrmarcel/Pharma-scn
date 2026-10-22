<div>
    <x-ui.toolbar>
        <x-ui.field label="Action" for="action"><input id="action" wire:model.live.debounce.500ms="action" class="form-input w-64" placeholder="e.g. purchase_order.approved"></x-ui.field>
        <x-ui.field label="Record id" for="subjectId"><input id="subjectId" wire:model.live.debounce.500ms="subjectId" class="form-input w-64 font-mono" placeholder="ULID"></x-ui.field>
    </x-ui.toolbar>

    <div class="card overflow-hidden">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>When</th><th>Who</th><th>Action</th><th>Record</th><th>Changes</th><th></th></tr></thead>
                <tbody>
                    @forelse ($rows as $r)
                        <tr wire:key="a-{{ $r['id'] }}">
                            <td class="whitespace-nowrap">{{ fmt()->dateTime($r['created_at']) }}</td>
                            <td>{{ $r['actor']['name'] ?? ($r['actor']['type'] ?? 'system') }}</td>
                            <td class="font-mono text-xs">{{ $r['action'] }}</td>
                            <td class="text-xs"><span class="text-slate-500">{{ class_basename($r['subject']['type'] ?? '') }}</span> <span class="font-mono">{{ \Illuminate\Support\Str::limit((string) ($r['subject']['id'] ?? ''), 12, '…') }}</span></td>
                            <td class="max-w-xs truncate text-xs text-slate-500">{{ implode(', ', array_keys($r['new_values'] ?? [])) }}</td>
                            <td class="text-right"><button type="button" class="link text-xs" wire:click="show(@js($r))">Details</button></td>
                        </tr>
                    @empty
                        <x-ui.empty colspan="6" icon="clipboard-document-list" title="No audit entries match"/>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-ui.pager :paginator="$meta"/>
    </div>

    <x-ui.modal wire:model="entry" title="Audit entry" size="lg">
        @if ($entry)
            <x-ui.dl :items="['Action' => $entry['action'], 'When' => fmt()->dateTime($entry['created_at']), 'Who' => $entry['actor']['name'] ?? ($entry['actor']['type'] ?? 'system'),
                'Record' => class_basename($entry['subject']['type'] ?? '').' '.($entry['subject']['id'] ?? ''), 'Correlation id' => $entry['correlation_id']]"/>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div><p class="mb-1 text-xs font-semibold uppercase text-slate-400">Before</p><pre class="max-h-72 overflow-auto rounded-lg bg-slate-50 p-3 text-xs">{{ json_encode($entry['old_values'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre></div>
                <div><p class="mb-1 text-xs font-semibold uppercase text-slate-400">After</p><pre class="max-h-72 overflow-auto rounded-lg bg-slate-50 p-3 text-xs">{{ json_encode($entry['new_values'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre></div>
            </div>
            @if ($entry['metadata'])
                <p class="mb-1 mt-4 text-xs font-semibold uppercase text-slate-400">Context</p>
                <pre class="max-h-48 overflow-auto rounded-lg bg-slate-50 p-3 text-xs">{{ json_encode($entry['metadata'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
            @endif
        @endif
    </x-ui.modal>
</div>

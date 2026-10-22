<div>
    <x-ui.toolbar>
        <x-ui.field label="Status" for="status"><select id="status" wire:model.live="status" class="form-select w-40"><option value="">All</option>@foreach (['received', 'processing', 'processed', 'quarantined', 'failed', 'ignored'] as $s)<option value="{{ $s }}">{{ ucfirst($s) }}</option>@endforeach</select></x-ui.field>
        <x-ui.field label="Connector" for="connector"><select id="connector" wire:model.live="connector" class="form-select w-48"><option value="">All</option>@foreach ($connectors as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select></x-ui.field>
        <x-ui.field label="Entity" for="entity"><input id="entity" wire:model.live.debounce.500ms="entity" class="form-input w-36" placeholder="inventory_snapshot"></x-ui.field>
        <x-ui.field label="Message id" for="messageId"><input id="messageId" wire:model.live.debounce.500ms="messageId" class="form-input w-48 font-mono"></x-ui.field>
    </x-ui.toolbar>

    <div class="card overflow-hidden">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Received</th><th>Message id</th><th>Connector</th><th>Entity</th><th class="num">Attempts</th><th>Problem</th><th>Status</th></tr></thead>
                <tbody>
                    @forelse ($rows as $m)
                        <tr wire:key="m-{{ $m['id'] }}" class="cursor-pointer" wire:click="$set('openId', '{{ $m['id'] }}')">
                            <td class="whitespace-nowrap">{{ $m['received_at'] ? fmt()->dateTime($m['received_at']) : '—' }}</td>
                            <td class="max-w-[12rem] truncate font-mono text-xs">{{ $m['message_id'] }}</td>
                            <td>{{ $connectors[$m['connector_id']] ?? '' }}</td>
                            <td>{{ str_replace('_', ' ', $m['entity_type']) }}</td>
                            <td class="num">{{ $m['attempts'] }}</td>
                            <td class="max-w-xs truncate text-xs text-rose-700">{{ $m['error']['message'] ?? '' }}</td>
                            <td><x-ui.badge :status="$m['status']"/></td>
                        </tr>
                    @empty
                        <x-ui.empty colspan="7" icon="envelope" title="No messages"/>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-ui.pager :paginator="$meta"/>
    </div>

    <x-ui.modal wire:model="openId" :title="'Message '.($detail['message_id'] ?? '')" size="xl">
        @if ($detail)
            <x-ui.dl :cols="3" :items="['Status' => $detail['status'], 'Entity' => $detail['entity_type'], 'Connector' => $connectors[$detail['connector_id']] ?? null,
                'Received' => $detail['received_at'] ? fmt()->dateTime($detail['received_at']) : null, 'Processed' => $detail['processed_at'] ? fmt()->dateTime($detail['processed_at']) : null,
                'Next attempt' => $detail['next_attempt_at'] ? fmt()->dateTime($detail['next_attempt_at']) : null]"/>
            @if ($detail['error'])
                <div class="mt-4 rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-900"><p class="font-semibold">{{ $detail['error']['code'] }}</p><p>{{ $detail['error']['message'] }}</p>
                    @if (! empty($detail['error']['details']))<pre class="mt-2 overflow-x-auto text-xs">{{ json_encode($detail['error']['details'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>@endif</div>
            @endif
            @if ($detail['resolution'])<p class="mt-3 text-sm text-slate-600">Resolved {{ fmt()->dateTime($detail['resolution']['at']) }}: {{ $detail['resolution']['notes'] }}</p>@endif
            <div class="mt-4 grid gap-4 lg:grid-cols-2">
                <div><p class="mb-1 text-xs font-semibold uppercase text-slate-400">As received (personal data masked)</p><pre class="max-h-72 overflow-auto rounded-lg bg-slate-50 p-3 text-xs">{{ json_encode($detail['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre></div>
                <div><p class="mb-1 text-xs font-semibold uppercase text-slate-400">After mapping</p><pre class="max-h-72 overflow-auto rounded-lg bg-slate-50 p-3 text-xs">{{ $detail['canonical'] ? json_encode($detail['canonical'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : 'Not mapped' }}</pre></div>
            </div>
            @if ($detail['result'])<p class="mt-3 text-xs text-slate-500">Result: {{ json_encode($detail['result'], JSON_UNESCAPED_SLASHES) }}</p>@endif

            @if ($mapping)
                <form wire:submit="map" class="mt-4 space-y-3 rounded-lg border border-slate-200 bg-slate-50 p-4">
                    <p class="text-sm text-slate-600">Tell the platform which of your products or warehouses this system's code means. The mapping is saved for future messages.</p>
                    <x-ui.field label="Product" for="map.product_id">@include('livewire.partials.product-picker', ['target' => 'map', 'selected' => $mapping['product_name']])</x-ui.field>
                    <x-ui.field label="or warehouse" for="map.warehouse_id"><select wire:model="mapping.warehouse_id" class="form-select py-2"><option value="">—</option>@foreach ($warehouses as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select></x-ui.field>
                    <div class="flex justify-end gap-2"><button type="button" class="btn-light btn-sm" wire:click="$set('mapping', null)">Back</button><button class="btn-primary btn-sm">Map and reprocess</button></div>
                </form>
            @elseif ($canManage && in_array($detail['status'], ['quarantined', 'failed'], true))
                <x-ui.field label="Reason (to ignore)" for="ignore.reason" class="mt-4"><input id="ignore.reason" wire:model="reason" class="form-input py-2"></x-ui.field>
            @endif
        @endif
        <x-slot:footer>
            @if ($detail && $canManage && in_array($detail['status'], ['quarantined', 'failed'], true) && ! $mapping)
                <button type="button" class="btn-light" wire:click="ignore">Ignore</button>
                <button type="button" class="btn-light" wire:click="startMap">Map a code</button>
                <button type="button" class="btn-primary" wire:click="retry">Retry</button>
            @endif
        </x-slot:footer>
    </x-ui.modal>
</div>

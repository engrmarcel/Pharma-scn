<div>
    <x-ui.toolbar>
        <p class="text-sm text-slate-600">A terminal sells from one warehouse. Cashiers choose a terminal when they open a shift.</p>
        <x-slot:actions><button type="button" class="btn-primary" wire:click="create"><x-heroicon-o-plus class="size-4"/>New terminal</button></x-slot:actions>
    </x-ui.toolbar>

    <div class="card overflow-hidden">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Code</th><th>Name</th><th>Sells from</th><th>Device</th><th>Last seen</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    @forelse ($terminals as $t)
                        <tr wire:key="t-{{ $t['id'] }}">
                            <td class="font-mono text-xs font-semibold">{{ $t['code'] }}</td>
                            <td class="font-medium text-navy-900">{{ $t['name'] }}</td>
                            <td>{{ $warehouses[$t['warehouse_id']] ?? '' }}</td>
                            <td class="font-mono text-xs">{{ $t['device_identifier'] ?? '—' }}</td>
                            <td>{{ $t['last_seen_at'] ? fmt()->dateTime($t['last_seen_at']) : '—' }}</td>
                            <td><x-ui.badge :status="$t['status']"/></td>
                            <td class="text-right">
                                @if ($t['status'] === 'active')
                                    <button type="button" class="link text-xs text-rose-700" wire:click="setStatus('{{ $t['id'] }}', 'disabled')" wire:confirm="Disable this terminal? Shifts can no longer be opened on it.">Disable</button>
                                @else
                                    <button type="button" class="link text-xs" wire:click="setStatus('{{ $t['id'] }}', 'active')">Enable</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <x-ui.empty colspan="7" icon="computer-desktop" title="No terminals yet"/>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <x-ui.modal wire:model="terminal" title="New terminal" size="sm">
        @if ($terminal)
            <form wire:submit="save" id="term-form" class="space-y-4">
                <x-ui.field label="Sells from warehouse" for="terminal.warehouse_id">
                    <select id="terminal.warehouse_id" wire:model="terminal.warehouse_id" class="form-select"><option value="">Choose…</option>@foreach ($warehouses as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
                </x-ui.field>
                <x-ui.field label="Code" for="terminal.code"><input id="terminal.code" wire:model="terminal.code" class="form-input font-mono uppercase" placeholder="TILL-1"></x-ui.field>
                <x-ui.field label="Name" for="terminal.name"><input id="terminal.name" wire:model="terminal.name" class="form-input" placeholder="Front counter"></x-ui.field>
                <x-ui.field label="Device identifier (optional)" for="terminal.device_identifier"><input id="terminal.device_identifier" wire:model="terminal.device_identifier" class="form-input font-mono"></x-ui.field>
            </form>
        @endif
        <x-slot:footer>
            <button type="button" class="btn-light" @click="open = null">Cancel</button>
            <button type="submit" form="term-form" class="btn-primary">Register</button>
        </x-slot:footer>
    </x-ui.modal>
</div>

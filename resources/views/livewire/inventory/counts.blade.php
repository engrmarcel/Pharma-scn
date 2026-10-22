<div>
    <x-ui.toolbar>
        <p class="text-sm text-slate-600">Count what is on the shelf; posting adjusts the ledger by the difference.</p>
        <x-slot:actions>
            @if ($canCount)<button type="button" class="btn-primary" wire:click="create"><x-heroicon-o-plus class="size-4"/>New count</button>@endif
        </x-slot:actions>
    </x-ui.toolbar>

    <div class="card overflow-hidden">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Number</th><th>Warehouse</th><th>Opened</th><th class="num">Lines counted</th><th>Status</th><th>Posted</th></tr></thead>
                <tbody>
                    @forelse ($rows as $c)
                        <tr wire:key="c-{{ $c['id'] }}" class="cursor-pointer" wire:click="open('{{ $c['id'] }}')">
                            <td class="font-mono text-xs font-semibold">{{ $c['number'] }}</td>
                            <td>{{ $warehouses[$c['warehouse_id']] ?? '' }}</td>
                            <td>{{ fmt()->dateTime($c['created_at']) }}</td>
                            <td class="num">{{ count($c['lines'] ?? []) }}</td>
                            <td><x-ui.badge :status="$c['status']"/></td>
                            <td>{{ $c['posted_at'] ? fmt()->dateTime($c['posted_at']) : '—' }}</td>
                        </tr>
                    @empty
                        <x-ui.empty colspan="6" icon="clipboard-document-check" title="No counts yet"/>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-ui.pager :paginator="$meta"/>
    </div>

    <x-ui.modal wire:model="form" title="Open a stock count" size="sm">
        @if ($form)
            <form wire:submit="save" id="count-form" class="space-y-4">
                <x-ui.field label="Warehouse" for="form.warehouse_id">
                    <select id="form.warehouse_id" wire:model="form.warehouse_id" class="form-select"><option value="">Choose…</option>@foreach ($warehouses as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
                </x-ui.field>
                <x-ui.field label="Notes" for="form.notes"><input id="form.notes" wire:model="form.notes" class="form-input" placeholder="Month-end count, aisle A"></x-ui.field>
            </form>
        @endif
        <x-slot:footer>
            <button type="button" class="btn-light" @click="open = null">Cancel</button>
            <button type="submit" form="count-form" class="btn-primary">Open count</button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal wire:model="openId" :title="'Count '.($count['number'] ?? '').' · '.($warehouses[$count['warehouse_id'] ?? ''] ?? '')" size="xl">
        @if ($count)
            @error('sheet.lines') <p class="mb-2 text-xs text-rose-600">{{ $message }}</p> @enderror
            <div class="table-wrap rounded-lg border border-slate-200">
                <table class="data-table">
                    <thead><tr><th>Product</th><th>Batch</th><th>Expiry</th><th class="num">System</th><th class="num">Counted</th><th class="num">Variance</th></tr></thead>
                    <tbody>
                        @forelse ($sheet as $s)
                            @php
                                $system = $s['line']['system_quantity'] ?? $s['system'];
                                $typed = $counted[$s['batch_id']] ?? '';
                                $variance = $typed !== '' && is_numeric($typed) ? (float) $typed - (float) $system : null;
                            @endphp
                            <tr wire:key="s-{{ $s['batch_id'] }}">
                                <td class="font-medium text-navy-900">{{ $names[$s['product_id']] ?? '' }}</td>
                                <td class="font-mono text-xs">{{ $s['batch_number'] ?? ($batches[$s['batch_id']] ?? '') }}</td>
                                <td>{{ $s['expiry_date'] ? fmt()->date($s['expiry_date']) : '—' }}</td>
                                <td class="num">{{ fmt()->number($system, 2) }}</td>
                                <td class="num">
                                    @if ($count['status'] === 'open')
                                        <input wire:model.live.debounce.400ms="counted.{{ $s['batch_id'] }}" inputmode="decimal" class="form-input w-28 py-1.5 text-right" aria-label="Counted">
                                    @else
                                        {{ $s['line'] ? fmt()->number($s['line']['counted_quantity'], 2) : '—' }}
                                    @endif
                                </td>
                                <td class="num font-semibold {{ $variance === null ? '' : ($variance < 0 ? 'text-rose-700' : ($variance > 0 ? 'text-emerald-700' : 'text-slate-400')) }}">{{ $variance === null ? '—' : ($variance > 0 ? '+' : '').fmt()->number($variance, 2) }}</td>
                            </tr>
                        @empty
                            <x-ui.empty colspan="6" title="No available stock in this warehouse"/>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <p class="mt-2 text-xs text-slate-500">System quantities are live until the count is posted; posting records the balance at that moment.</p>
        @endif
        <x-slot:footer>
            @if (($count['status'] ?? null) === 'open')
                @if ($canCount)<button type="button" class="btn-light" wire:click="saveCounts">Save counts</button>@endif
                @if ($canPost)<button type="button" class="btn-primary" wire:click="post" wire:confirm="Post this count? Differences are written to the stock ledger.">Post count</button>@endif
            @else
                <button type="button" class="btn-light" @click="open = null">Close</button>
            @endif
        </x-slot:footer>
    </x-ui.modal>
</div>

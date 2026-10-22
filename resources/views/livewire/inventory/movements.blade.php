<div>
    <x-ui.toolbar>
        <x-ui.field label="Warehouse" for="warehouse">
            <select id="warehouse" wire:model.live="warehouse" class="form-select w-56"><option value="">All warehouses</option>@foreach ($warehouses as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
        </x-ui.field>
        <x-ui.field label="Type" for="type">
            <select id="type" wire:model.live="type" class="form-select w-44">
                <option value="">All types</option>
                @foreach (['receipt', 'adjustment', 'transfer_out', 'transfer_in', 'state_change', 'sale', 'sale_return', 'count', 'write_off'] as $t)<option value="{{ $t }}">{{ str_replace('_', ' ', $t) }}</option>@endforeach
            </select>
        </x-ui.field>
        <x-ui.field label="From" for="from"><input id="from" type="date" wire:model.live="from" class="form-input w-40"></x-ui.field>
        <x-ui.field label="To" for="to"><input id="to" type="date" wire:model.live="to" class="form-input w-40"></x-ui.field>
    </x-ui.toolbar>

    <div class="card overflow-hidden">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Posted</th><th>Type</th><th>Product</th><th>Batch</th><th>Warehouse</th><th>State</th><th class="num">Quantity</th><th class="num">Balance after</th><th>Reference</th></tr></thead>
                <tbody>
                    @forelse ($rows as $m)
                        <tr wire:key="m-{{ $m['id'] }}">
                            <td class="whitespace-nowrap">{{ fmt()->dateTime($m['posted_at']) }}</td>
                            <td><x-ui.badge tone="blue">{{ str_replace('_', ' ', $m['movement_type']) }}</x-ui.badge>@if ($m['reason_code'])<span class="muted block">{{ str_replace('_', ' ', $m['reason_code']) }}</span>@endif</td>
                            <td class="font-medium text-navy-900">{{ $names[$m['product_id']] ?? '' }}</td>
                            <td class="font-mono text-xs">{{ $batches[$m['batch_id']] ?? '' }}</td>
                            <td>{{ $warehouses[$m['warehouse_id']] ?? '' }}</td>
                            <td>{{ str_replace('_', ' ', $m['stock_state']) }}</td>
                            <td class="num font-semibold {{ (float) $m['quantity'] < 0 ? 'text-rose-700' : 'text-emerald-700' }}">{{ (float) $m['quantity'] > 0 ? '+' : '' }}{{ fmt()->number($m['quantity'], 2) }}</td>
                            <td class="num">{{ fmt()->number($m['balance_after'], 2) }}</td>
                            <td class="text-xs text-slate-500">{{ $m['reference_type'] ? class_basename(str_replace('_', ' ', $m['reference_type'])) : '—' }}</td>
                        </tr>
                    @empty
                        <x-ui.empty colspan="9" icon="arrows-right-left" title="No movements match"/>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-ui.pager :paginator="$meta"/>
    </div>
</div>

<div>
    <x-ui.tabs :current="$tab" :tabs="array_filter(['levels' => 'By product', 'low' => 'Low stock', 'expiring' => 'Expiring', 'valuation' => $canValue ? 'Valuation' : null])"/>

    <x-ui.toolbar>
        @if ($tab !== 'low')
            <x-ui.field label="Warehouse" for="warehouse">
                <select id="warehouse" wire:model.live="warehouse" class="form-select w-60"><option value="">All warehouses</option>@foreach ($warehouses as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
            </x-ui.field>
        @endif
        @if ($tab === 'expiring')
            <x-ui.field label="Expiring within" for="days">
                <select id="days" wire:model.live="days" class="form-select w-40">@foreach ([30, 60, 90, 180, 365] as $d)<option value="{{ $d }}">{{ $d }} days</option>@endforeach</select>
            </x-ui.field>
        @endif
        <x-slot:actions>
            @can('inventory.receive')<a href="{{ route('inventory.receipts') }}" class="btn-light"><x-heroicon-o-inbox-arrow-down class="size-4"/>Receive stock</a>@endcan
        </x-slot:actions>
    </x-ui.toolbar>

    <div class="card overflow-hidden">
        <div class="table-wrap">
            @if ($tab === 'levels')
                <table class="data-table">
                    <thead><tr><th>Product</th><th>Warehouse</th><th class="num">On hand</th><th class="num">Reserved</th><th class="num">Sellable</th><th class="num">Blocked</th><th class="num">Quarantined</th><th class="num">In transit</th><th>Nearest expiry</th></tr></thead>
                    <tbody>
                        @forelse ($rows as $r)
                            <tr wire:key="sl-{{ $r['product_id'] }}-{{ $r['warehouse_id'] }}" class="cursor-pointer" wire:click="openDrill('{{ $r['product_id'] }}', '{{ $r['warehouse_id'] }}')">
                                <td class="font-medium text-navy-900">{{ $r['product_name'] }}</td>
                                <td class="font-mono text-xs">{{ $r['warehouse_code'] }}</td>
                                <td class="num">{{ fmt()->number($r['available_on_hand'], 2) }}</td>
                                <td class="num">{{ fmt()->number($r['reserved'], 2) }}</td>
                                <td class="num font-semibold text-emerald-700">{{ fmt()->number($r['sellable'], 2) }}</td>
                                <td class="num {{ (float) $r['blocked_in_available'] ? 'text-amber-700' : '' }}">{{ fmt()->number($r['blocked_in_available'], 2) }}</td>
                                <td class="num {{ (float) $r['quarantined'] ? 'text-rose-700' : '' }}">{{ fmt()->number($r['quarantined'], 2) }}</td>
                                <td class="num">{{ fmt()->number($r['in_transit'], 2) }}</td>
                                <td>{{ $r['nearest_sellable_expiry'] ? fmt()->date($r['nearest_sellable_expiry']) : '—' }}</td>
                            </tr>
                        @empty
                            <x-ui.empty colspan="9" icon="cube" title="No stock recorded">Receive stock or post goods receipts to see balances here.</x-ui.empty>
                        @endforelse
                    </tbody>
                </table>
            @elseif ($tab === 'low')
                <table class="data-table">
                    <thead><tr><th>SKU</th><th>Product</th><th class="num">Sellable</th><th class="num">Reorder level</th><th class="num">Reorder quantity</th></tr></thead>
                    <tbody>
                        @forelse ($rows as $r)
                            <tr><td class="font-mono text-xs">{{ $r['sku'] }}</td><td class="font-medium text-navy-900">{{ $r['product_name'] }}</td>
                                <td class="num font-semibold text-rose-700">{{ fmt()->number($r['sellable'], 2) }}</td><td class="num">{{ fmt()->number($r['reorder_level'], 2) }}</td><td class="num">{{ $r['reorder_quantity'] !== null ? fmt()->number($r['reorder_quantity'], 2) : '—' }}</td></tr>
                        @empty
                            <x-ui.empty colspan="5" icon="check-circle" title="Nothing below its reorder level">Reorder levels are set per product in Our catalogue.</x-ui.empty>
                        @endforelse
                    </tbody>
                </table>
            @elseif ($tab === 'expiring')
                <table class="data-table">
                    <thead><tr><th>Product</th><th>Batch</th><th>Warehouse</th><th>Expiry</th><th>State</th><th class="num">Quantity</th></tr></thead>
                    <tbody>
                        @forelse ($rows as $r)
                            <tr><td class="font-medium text-navy-900">{{ $r['product_name'] }}</td><td class="font-mono text-xs">{{ $r['batch_number'] }}</td><td>{{ $warehouses[$r['warehouse_id']] ?? '' }}</td>
                                <td>{{ fmt()->date($r['expiry_date']) }} @if ($r['expired'])<x-ui.badge status="expired" class="ml-1"/>@endif</td><td><x-ui.badge :status="$r['stock_state']"/></td><td class="num">{{ fmt()->number($r['quantity'], 2) }}</td></tr>
                        @empty
                            <x-ui.empty colspan="6" icon="calendar" title="Nothing expiring in this window"/>
                        @endforelse
                    </tbody>
                </table>
            @else
                <table class="data-table">
                    <thead><tr><th>Warehouse</th><th>State</th><th class="num">Value</th><th class="num">Quantity without cost</th></tr></thead>
                    <tbody>
                        @forelse (collect($rows ?? [])->when($warehouse, fn ($c) => $c->where('warehouse_id', $warehouse)) as $r)
                            <tr><td>{{ $warehouses[$r['warehouse_id']] ?? '' }}</td><td><x-ui.badge :status="$r['stock_state']"/></td>
                                <td class="num font-semibold">{{ $r['currency_code'] ? fmt()->money($r['value'], $r['currency_code']) : fmt()->number($r['value'], 2) }}</td><td class="num">{{ fmt()->number($r['uncosted_quantity'], 2) }}</td></tr>
                        @empty
                            <x-ui.empty colspan="4" icon="banknotes" title="No stock to value"/>
                        @endforelse
                    </tbody>
                    @if (! empty($rows))
                        <tfoot><tr class="border-t-2 border-slate-200 font-semibold"><td colspan="2" class="px-4 py-3">Total</td>
                            <td class="num px-4 py-3">@foreach (collect($rows)->groupBy('currency_code') as $cur => $g){{ $cur ? fmt()->money($g->sum(fn ($r) => (float) $r['value']), $cur) : '' }} @endforeach</td><td></td></tr></tfoot>
                    @endif
                </table>
            @endif
        </div>
        @if ($tab === 'levels')<x-ui.pager :paginator="$meta"/>@endif
    </div>

    <x-ui.modal wire:model="drill" :title="'Batches · '.$drillName" size="lg">
        <div class="table-wrap rounded-lg border border-slate-200">
            <table class="data-table">
                <thead><tr><th>Batch</th><th>Expiry</th><th>Quality</th><th>State</th><th class="num">Quantity</th><th class="num">Reserved</th></tr></thead>
                <tbody>
                    @forelse ($drillRows as $b)
                        <tr><td class="font-mono text-xs font-semibold">{{ $b['batch_number'] }}</td><td>{{ $b['expiry_date'] ? fmt()->date($b['expiry_date']) : '—' }}</td>
                            <td><x-ui.badge :status="$b['quality_status']"/>@if ($b['recall_status'] !== 'none')<x-ui.badge status="recalled" class="ml-1"/>@endif</td>
                            <td><x-ui.badge :status="$b['stock_state']"/></td><td class="num">{{ fmt()->number($b['quantity'], 2) }}</td><td class="num">{{ fmt()->number($b['reserved_quantity'], 2) }}</td></tr>
                    @empty
                        <x-ui.empty colspan="6" title="No batch balances"/>
                    @endforelse
                </tbody>
            </table>
        </div>
        <p class="mt-3 text-xs text-slate-500">Quantities are in the product's base unit.</p>
    </x-ui.modal>
</div>

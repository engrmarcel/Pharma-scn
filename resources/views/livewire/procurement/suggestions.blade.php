<div>
    <x-ui.toolbar>
        <p class="max-w-2xl text-sm text-slate-600">{{ $note }} Quantities are in base units; the suggestion tops stock up to twice the reorder level unless a reorder quantity is set.</p>
        <x-slot:actions>
            @if ($canRaise && $rows)
                <x-ui.field for="raise.warehouse_id">
                    <select wire:model="warehouse" class="form-select w-56" aria-label="Deliver to"><option value="">Deliver to…</option>@foreach ($warehouses as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
                </x-ui.field>
                <button type="button" class="btn-primary" wire:click="raise"><x-heroicon-o-clipboard-document-list class="size-4"/>Raise requisition</button>
            @endif
        </x-slot:actions>
    </x-ui.toolbar>

    <div class="card overflow-hidden">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th class="w-10"></th><th>SKU</th><th>Product</th><th class="num">Sellable</th><th class="num">Reorder level</th><th class="num">Suggested</th><th>Preferred supplier</th><th class="num">List price</th></tr></thead>
                <tbody>
                    @forelse ($rows as $r)
                        <tr wire:key="s-{{ $r['product_id'] }}">
                            <td>@if ($canRaise)<input type="checkbox" wire:model="selected.{{ $r['product_id'] }}" class="form-check" aria-label="Select">@endif</td>
                            <td class="font-mono text-xs">{{ $r['sku'] }}</td>
                            <td class="font-medium text-navy-900">{{ $r['product_name'] }}</td>
                            <td class="num font-semibold text-rose-700">{{ fmt()->number($r['sellable'], 2) }}</td>
                            <td class="num">{{ fmt()->number($r['reorder_level'], 2) }}</td>
                            <td class="num font-semibold">{{ fmt()->number($r['suggested_base_quantity'], 2) }}</td>
                            <td>{{ $suppliers[$r['preferred_supplier_id'] ?? ''] ?? '—' }}</td>
                            <td class="num">{{ $r['list_price'] ? fmt()->number($r['list_price']['unit_price'], 2).' / '.$r['list_price']['package_level_code'] : '—' }}</td>
                        </tr>
                    @empty
                        <x-ui.empty colspan="8" icon="check-circle" title="Nothing needs reordering">Suggestions appear when sellable stock falls to a product's reorder level (set in Our catalogue).</x-ui.empty>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

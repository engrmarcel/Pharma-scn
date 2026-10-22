<div>
    <x-ui.toolbar>
        <x-ui.search placeholder="SKU, name or GTIN…"/>
        <x-slot:actions>
            <a href="{{ route('catalogue') }}" class="btn-light"><x-heroicon-o-beaker class="size-4"/>Browse product master</a>
            @if ($canManage)<button type="button" class="btn-primary" wire:click="create"><x-heroicon-o-plus class="size-4"/>Add product</button>@endif
        </x-slot:actions>
    </x-ui.toolbar>

    <div class="card overflow-hidden">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>SKU</th><th>Product</th><th class="num">Cost</th><th class="num">Price</th><th class="num">Reorder at</th><th>Preferred supplier</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    @forelse ($rows as $r)
                        <tr wire:key="i-{{ $r['id'] }}">
                            <td class="font-mono text-xs font-semibold">{{ $r['sku'] }}</td>
                            <td><span class="font-medium text-navy-900">{{ $r['local_name'] ?: ($r['product']['display_name'] ?? '') }}</span>
                                @if ($r['local_name'])<span class="muted block">{{ $r['product']['display_name'] ?? '' }}</span>@endif</td>
                            <td class="num">{{ $r['default_purchase_cost'] !== null ? fmt()->money($r['default_purchase_cost'], $r['currency_code']) : '—' }}</td>
                            <td class="num">{{ $r['default_selling_price'] !== null ? fmt()->money($r['default_selling_price'], $r['currency_code']) : '—' }}</td>
                            <td class="num">{{ $r['reorder_level'] !== null ? fmt()->number($r['reorder_level'], 2) : '—' }}</td>
                            <td>{{ $suppliers[$r['preferred_supplier_id'] ?? ''] ?? '—' }}</td>
                            <td><x-ui.badge :status="$r['status']"/></td>
                            <td class="text-right">@if ($canManage)<button type="button" class="link text-xs" wire:click="edit(@js($r))">Edit</button>@endif</td>
                        </tr>
                    @empty
                        <x-ui.empty colspan="8" icon="rectangle-stack" title="{{ $search ? 'No products match' : 'Your catalogue is empty' }}">Add the products you buy, stock or sell from the shared product master.</x-ui.empty>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-ui.pager :paginator="$meta"/>
    </div>

    <x-ui.modal wire:model="item" :title="($item['id'] ?? null) ? 'Edit catalogue item' : 'Add a product to your catalogue'" size="lg">
        @if ($item)
            <form wire:submit="save" id="item-form" class="grid gap-4 sm:grid-cols-2">
                <x-ui.field label="Product" for="item.product_id" class="sm:col-span-2">
                    @if ($item['id'])
                        <p class="form-input bg-slate-50">{{ $item['product_name'] }}</p>
                    @else
                        @include('livewire.partials.product-picker', ['target' => 'item', 'selected' => $item['product_name']])
                    @endif
                </x-ui.field>
                <x-ui.field label="Your SKU" for="item.sku"><input id="item.sku" wire:model="item.sku" class="form-input font-mono"></x-ui.field>
                <x-ui.field label="Local name" for="item.local_name" hint="Optional: the name you use."><input id="item.local_name" wire:model="item.local_name" class="form-input"></x-ui.field>
                <x-ui.field label="Default purchase cost" for="item.default_purchase_cost"><input id="item.default_purchase_cost" wire:model="item.default_purchase_cost" inputmode="decimal" class="form-input"></x-ui.field>
                <x-ui.field label="Default selling price" for="item.default_selling_price"><input id="item.default_selling_price" wire:model="item.default_selling_price" inputmode="decimal" class="form-input"></x-ui.field>
                <x-ui.field label="Reorder level (base units)" for="item.reorder_level"><input id="item.reorder_level" wire:model="item.reorder_level" inputmode="decimal" class="form-input"></x-ui.field>
                <x-ui.field label="Reorder quantity" for="item.reorder_quantity"><input id="item.reorder_quantity" wire:model="item.reorder_quantity" inputmode="decimal" class="form-input"></x-ui.field>
                <x-ui.field label="Preferred supplier" for="item.preferred_supplier_id">
                    <select id="item.preferred_supplier_id" wire:model="item.preferred_supplier_id" class="form-select"><option value="">None</option>@foreach ($suppliers as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
                </x-ui.field>
                <x-ui.field label="Tax category" for="item.tax_category_code"><input id="item.tax_category_code" wire:model="item.tax_category_code" class="form-input" placeholder="standard"></x-ui.field>
                <x-ui.field label="Status" for="item.status">
                    <select id="item.status" wire:model="item.status" class="form-select"><option value="active">Active</option><option value="inactive">Inactive</option></select>
                </x-ui.field>
            </form>
        @endif
        <x-slot:footer>
            <button type="button" class="btn-light" @click="open = null">Cancel</button>
            <button type="submit" form="item-form" class="btn-primary" wire:loading.attr="disabled">Save</button>
        </x-slot:footer>
    </x-ui.modal>
</div>

<div>
    <x-ui.toolbar>
        <x-ui.field label="Status" for="status"><select id="status" wire:model.live="status" class="form-select w-36"><option value="">All</option><option value="active">Active</option><option value="paused">Paused</option></select></x-ui.field>
        <x-slot:actions><button type="button" class="btn-primary" wire:click="create"><x-heroicon-o-plus class="size-4"/>New listing</button></x-slot:actions>
    </x-ui.toolbar>

    <div class="card overflow-hidden">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Product</th><th>Pack</th><th class="num">Price</th><th class="num">Minimum</th><th>Availability</th><th>Buyers</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    @forelse ($rows as $l)
                        <tr wire:key="l-{{ $l['id'] }}">
                            <td class="font-medium text-navy-900">{{ $names[$l['product_id']] ?? '' }}</td>
                            <td>{{ $l['package_level_code'] }}</td>
                            <td class="num font-semibold">{{ fmt()->money($l['unit_price'], $l['currency_code']) }}</td>
                            <td class="num">{{ fmt()->number($l['minimum_order_quantity'], 0) }}</td>
                            <td>{{ $l['availability_mode'] === 'live' ? 'Live stock'.($l['warehouse_id'] ? ' · '.($warehouses[$l['warehouse_id']] ?? '') : '') : str_replace('_', ' ', $l['declared_availability']) }}</td>
                            <td class="text-xs">{{ $l['allowed_buyer_types'] ? implode(', ', array_map(fn ($t) => str_replace('_', ' ', $t), (array) $l['allowed_buyer_types'])) : 'Any verified buyer' }}</td>
                            <td><x-ui.badge :status="$l['status']"/></td>
                            <td class="text-right"><button type="button" class="link text-xs" wire:click="edit(@js($l), @js($names[$l['product_id']] ?? ''))">Edit</button></td>
                        </tr>
                    @empty
                        <x-ui.empty colspan="8" icon="rectangle-stack" title="Nothing listed yet">Listings show your products to verified buyers who trade with you.</x-ui.empty>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-ui.pager :paginator="$meta"/>
    </div>

    <x-ui.modal wire:model="form" :title="($form['id'] ?? null) ? 'Edit listing' : 'New listing'" size="lg">
        @if ($form)
            <form wire:submit="save" id="list-form" class="grid gap-4 sm:grid-cols-2">
                <x-ui.field label="Product (from your catalogue)" for="form.product_id" class="sm:col-span-2">
                    @if ($form['id'])<p class="form-input bg-slate-50">{{ $form['product_name'] }} · {{ $form['package_level_code'] }}</p>
                    @else @include('livewire.partials.product-picker', ['target' => 'listing', 'selected' => $form['product_name']]) @endif
                </x-ui.field>
                @unless ($form['id'])
                    <x-ui.field label="Pack" for="form.package_level_code"><select id="form.package_level_code" wire:model="form.package_level_code" class="form-select">@forelse ($form['levels'] as $c => $n)<option value="{{ $c }}">{{ $n }}</option>@empty<option value="">Choose a product</option>@endforelse</select></x-ui.field>
                @endunless
                <x-ui.field label="Unit price" for="form.unit_price"><input id="form.unit_price" wire:model="form.unit_price" inputmode="decimal" class="form-input"></x-ui.field>
                <x-ui.field label="Minimum order (packs)" for="form.minimum_order_quantity"><input id="form.minimum_order_quantity" wire:model="form.minimum_order_quantity" inputmode="decimal" class="form-input"></x-ui.field>
                <x-ui.field label="Lead time (days)" for="form.lead_time_days"><input id="form.lead_time_days" type="number" min="0" wire:model="form.lead_time_days" class="form-input"></x-ui.field>
                <x-ui.field label="Availability" for="form.availability_mode"><select id="form.availability_mode" wire:model.live="form.availability_mode" class="form-select"><option value="live">Live from stock</option><option value="declared">Declared by us</option></select></x-ui.field>
                @if ($form['availability_mode'] === 'declared')
                    <x-ui.field label="Declared availability" for="form.declared_availability"><select id="form.declared_availability" wire:model="form.declared_availability" class="form-select">@foreach ($declared as $d)<option value="{{ $d }}">{{ ucfirst(str_replace('_', ' ', $d)) }}</option>@endforeach</select></x-ui.field>
                @else
                    <x-ui.field label="Stock from" for="form.warehouse_id"><select id="form.warehouse_id" wire:model="form.warehouse_id" class="form-select"><option value="">All warehouses</option>@foreach ($warehouses as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select></x-ui.field>
                @endif
                <x-ui.field label="Status" for="form.status"><select id="form.status" wire:model="form.status" class="form-select"><option value="active">Active</option><option value="paused">Paused</option></select></x-ui.field>
                <div class="sm:col-span-2">
                    <p class="form-label">Only these buyer types (none ticked: any)</p>
                    <div class="flex flex-wrap gap-x-4 gap-y-1">@foreach ($types as $t)<label class="flex items-center gap-1.5 text-sm"><input type="checkbox" value="{{ $t->value }}" wire:model="form.allowed_buyer_types" class="form-check">{{ $t->label() }}</label>@endforeach</div>
                </div>
            </form>
        @endif
        <x-slot:footer><button type="button" class="btn-light" @click="open = null">Cancel</button><button type="submit" form="list-form" class="btn-primary">Save</button></x-slot:footer>
    </x-ui.modal>
</div>

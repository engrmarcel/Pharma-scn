<div class="grid gap-6 lg:grid-cols-[18rem_1fr]">
    <div class="space-y-2">
        @if ($canManage)<button type="button" class="btn-primary mb-2 w-full" wire:click="create"><x-heroicon-o-plus class="size-4"/>New price list</button>@endif
        @forelse ($lists as $l)
            <button type="button" wire:key="pl-{{ $l['id'] }}" wire:click="$set('selectedId', '{{ $l['id'] }}')"
                    class="block w-full rounded-xl border p-3 text-left transition {{ $selectedId === $l['id'] ? 'border-[var(--brand-primary)] bg-navy-50' : 'border-slate-200 bg-white hover:bg-slate-50' }}">
                <span class="flex items-center justify-between"><span class="font-semibold text-navy-950">{{ $l['name'] }}</span>@if ($l['is_default'])<x-ui.badge tone="green">default</x-ui.badge>@endif</span>
                <span class="muted">{{ $l['code'] }} · {{ $l['currency_code'] }} · {{ count($l['items']) }} prices{{ $l['branch_id'] ? ' · '.($branches[$l['branch_id']] ?? '') : '' }}</span>
            </button>
        @empty
            <x-ui.card><x-ui.empty icon="currency-dollar" title="No price lists">Without a price list, orders use the default selling price from Our catalogue.</x-ui.empty></x-ui.card>
        @endforelse
    </div>

    <div>
        @if ($selected && ! $editing)
            <x-ui.card :title="$selected['name']" icon="currency-dollar" :padded="false">
                <x-slot:actions>@if ($canManage)<button type="button" class="btn-light btn-sm" wire:click="editItems('{{ $selected['id'] }}')"><x-heroicon-o-pencil-square class="size-4"/>Edit prices</button>@endif</x-slot:actions>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead><tr><th>Product</th><th>Pack</th><th class="num">Price</th><th class="num">Min. qty</th><th>Valid</th></tr></thead>
                        <tbody>
                            @forelse ($selected['items'] as $i)
                                <tr><td class="font-medium text-navy-900">{{ $names[$i['product_id']] ?? '' }}</td><td>{{ $i['package_level_code'] }}</td><td class="num font-semibold">{{ fmt()->money($i['unit_price'], $selected['currency_code']) }}</td>
                                    <td class="num">{{ fmt()->number($i['minimum_quantity'], 2) }}</td><td class="text-xs">{{ $i['valid_from'] ? fmt()->date($i['valid_from']) : 'any' }} – {{ $i['valid_to'] ? fmt()->date($i['valid_to']) : 'open' }}</td></tr>
                            @empty
                                <x-ui.empty colspan="5" title="No prices yet"/>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-ui.card>
        @elseif ($editing)
            <x-ui.card title="Edit prices" icon="pencil-square">
                <form wire:submit="saveItems" class="space-y-2">
                    @error('items.items') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
                    @foreach ($editing['items'] as $i => $it)
                        <div class="grid items-start gap-2 rounded-lg border border-slate-200 p-2 md:grid-cols-[minmax(0,1fr)_9rem_8rem_6rem_9rem_9rem_auto]" wire:key="pi-{{ $i }}">
                            <x-ui.field :for="'items.items.'.$i.'.product_id'">@include('livewire.partials.product-picker', ['target' => 'item.'.$i, 'selected' => $it['name']])</x-ui.field>
                            <x-ui.field :for="'items.items.'.$i.'.package_level_code'"><select wire:model="editing.items.{{ $i }}.package_level_code" class="form-select py-2">@foreach ($it['levels'] as $code => $name)<option value="{{ $code }}">{{ $name }}</option>@endforeach</select></x-ui.field>
                            <x-ui.field :for="'items.items.'.$i.'.unit_price'"><input wire:model="editing.items.{{ $i }}.unit_price" inputmode="decimal" class="form-input py-2" placeholder="Price"></x-ui.field>
                            <x-ui.field :for="'items.items.'.$i.'.minimum_quantity'"><input wire:model="editing.items.{{ $i }}.minimum_quantity" inputmode="decimal" class="form-input py-2" placeholder="Min"></x-ui.field>
                            <input type="date" wire:model="editing.items.{{ $i }}.valid_from" class="form-input py-2" aria-label="Valid from">
                            <input type="date" wire:model="editing.items.{{ $i }}.valid_to" class="form-input py-2" aria-label="Valid to">
                            <button type="button" class="p-2 text-slate-400 hover:text-rose-600" wire:click="removeItem({{ $i }})" aria-label="Remove"><x-heroicon-o-trash class="size-4"/></button>
                        </div>
                    @endforeach
                    <div class="flex items-center justify-between pt-2">
                        <button type="button" class="link text-sm" wire:click="addItem">+ Add product</button>
                        <span class="flex gap-2"><button type="button" class="btn-light btn-sm" wire:click="$set('editing', null)">Cancel</button><button class="btn-primary btn-sm">Save prices</button></span>
                    </div>
                </form>
            </x-ui.card>
        @endif
    </div>

    <x-ui.modal wire:model="list" title="New price list" size="sm">
        @if ($list)
            <form wire:submit="save" id="pl-form" class="space-y-4">
                <x-ui.field label="Code" for="list.code"><input id="list.code" wire:model="list.code" class="form-input font-mono uppercase" placeholder="RETAIL"></x-ui.field>
                <x-ui.field label="Name" for="list.name"><input id="list.name" wire:model="list.name" class="form-input" placeholder="Retail prices"></x-ui.field>
                <x-ui.field label="Branch (optional)" for="list.branch_id">
                    <select id="list.branch_id" wire:model="list.branch_id" class="form-select"><option value="">All branches</option>@foreach ($branches as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
                </x-ui.field>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="list.is_default" class="form-check">Default list for customers without one</label>
            </form>
        @endif
        <x-slot:footer>
            <button type="button" class="btn-light" @click="open = null">Cancel</button>
            <button type="submit" form="pl-form" class="btn-primary">Create</button>
        </x-slot:footer>
    </x-ui.modal>
</div>

<div>
    <x-ui.toolbar>
        <x-ui.field label="Connector" for="connector"><select id="connector" wire:model.live="connector" class="form-select w-48"><option value="">All</option>@foreach ($connectors as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select></x-ui.field>
        <x-ui.field label="Kind" for="entity"><select id="entity" wire:model.live="entity" class="form-select w-36"><option value="">All</option><option value="product">Products</option><option value="warehouse">Warehouses</option></select></x-ui.field>
        <x-ui.field label="Their code" for="externalId"><input id="externalId" wire:model.live.debounce.500ms="externalId" class="form-input w-48 font-mono"></x-ui.field>
        <x-slot:actions>@if ($canManage)<button type="button" class="btn-primary" wire:click="create"><x-heroicon-o-plus class="size-4"/>Add reference</button>@endif</x-slot:actions>
    </x-ui.toolbar>
    <div class="card overflow-hidden">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Connector</th><th>Kind</th><th>Their code</th><th>Our record</th><th>Source</th><th></th></tr></thead>
                <tbody>
                    @forelse ($rows as $r)
                        <tr wire:key="x-{{ $r['id'] }}"><td>{{ $connectors[$r['connector_id']] ?? '' }}</td><td>{{ $r['entity_type'] }}</td><td class="font-mono text-xs">{{ $r['external_id'] }}</td>
                            <td class="font-medium text-navy-900">{{ $names[$r['internal_id']] ?? $r['internal_id'] }}</td><td><x-ui.badge tone="slate">{{ $r['source'] }}</x-ui.badge></td>
                            <td class="text-right">@if ($canManage)<button type="button" class="link text-xs text-rose-700" wire:click="remove('{{ $r['id'] }}')" wire:confirm="Remove this reference?">Remove</button>@endif</td></tr>
                    @empty
                        <x-ui.empty colspan="6" icon="link" title="No references">References are created when you map a code on a quarantined message, or here.</x-ui.empty>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-ui.pager :paginator="$meta"/>
    </div>
    <x-ui.modal wire:model="form" title="Add an external reference" size="md">
        @if ($form)
            <form wire:submit="save" id="ref-form" class="grid gap-4 sm:grid-cols-2">
                <x-ui.field label="Connector" for="form.connector_id"><select id="form.connector_id" wire:model="form.connector_id" class="form-select"><option value="">Choose…</option>@foreach ($connectors as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select></x-ui.field>
                <x-ui.field label="Kind" for="form.entity_type"><select id="form.entity_type" wire:model.live="form.entity_type" class="form-select"><option value="product">Product</option><option value="warehouse">Warehouse</option></select></x-ui.field>
                <x-ui.field label="Their code" for="form.external_id" class="sm:col-span-2"><input id="form.external_id" wire:model="form.external_id" class="form-input font-mono"></x-ui.field>
                <x-ui.field label="Our record" for="form.internal_id" class="sm:col-span-2">
                    @if ($form['entity_type'] === 'product')@include('livewire.partials.product-picker', ['target' => 'ref', 'selected' => $form['internal_name']])
                    @else<select wire:model="form.internal_id" class="form-select"><option value="">Choose…</option>@foreach ($warehouses as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select>@endif
                </x-ui.field>
            </form>
        @endif
        <x-slot:footer><button type="button" class="btn-light" @click="open = null">Cancel</button><button type="submit" form="ref-form" class="btn-primary">Save</button></x-slot:footer>
    </x-ui.modal>
</div>

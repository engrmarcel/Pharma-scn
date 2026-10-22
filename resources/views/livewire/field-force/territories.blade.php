<div>
    <x-ui.toolbar>
        <p class="text-sm text-slate-600">Representatives see the customers of their territories; supervisors see their territories' activity.</p>
        <x-slot:actions>@if ($canManage)<button type="button" class="btn-primary" wire:click="create"><x-heroicon-o-plus class="size-4"/>New territory</button>@endif</x-slot:actions>
    </x-ui.toolbar>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @forelse ($territories as $t)
            <div class="card p-5" wire:key="t-{{ $t['id'] }}">
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <p class="font-mono text-xs font-semibold text-gold-600">{{ $t['code'] }}</p>
                        <h3 class="text-lg font-semibold text-navy-950">{{ $t['name'] }}</h3>
                        <p class="text-xs text-slate-500">{{ $t['customer_count'] }} customers · supplied from {{ $warehouses[$t['warehouse_id'] ?? ''] ?? 'no warehouse set' }}</p>
                    </div>
                    <x-ui.badge :status="$t['status']"/>
                </div>
                @if ($t['description'])<p class="mt-2 text-sm text-slate-600">{{ $t['description'] }}</p>@endif
                <ul class="mt-3 space-y-1 text-sm">
                    @forelse ($t['members'] as $m)
                        <li class="flex items-center justify-between"><span>{{ $m['name'] }}</span><x-ui.badge :tone="$m['role'] === 'supervisor' ? 'gold' : 'blue'">{{ $m['role'] }}</x-ui.badge></li>
                    @empty
                        <li class="text-slate-500">No members yet.</li>
                    @endforelse
                </ul>
                @if ($canManage)
                    <div class="mt-4 flex flex-wrap gap-3 border-t border-slate-100 pt-3 text-xs">
                        <button type="button" class="link" wire:click="edit(@js($t))">Edit</button>
                        <button type="button" class="link" wire:click="editMembers(@js($t))">Members</button>
                        <button type="button" class="link" wire:click="startAssign(@js($t))">Assign customers</button>
                    </div>
                @endif
            </div>
        @empty
            <x-ui.card class="md:col-span-2 xl:col-span-3"><x-ui.empty icon="map" title="No territories yet"/></x-ui.card>
        @endforelse
    </div>

    <x-ui.modal wire:model="territory" :title="($territory['id'] ?? null) ? 'Edit territory' : 'New territory'" size="md">
        @if ($territory)
            <form wire:submit="save" id="terr-form" class="grid gap-4 sm:grid-cols-2">
                <x-ui.field label="Code" for="territory.code"><input id="territory.code" wire:model="territory.code" class="form-input font-mono uppercase"></x-ui.field>
                <x-ui.field label="Name" for="territory.name"><input id="territory.name" wire:model="territory.name" class="form-input"></x-ui.field>
                <x-ui.field label="Supplied from" for="territory.warehouse_id"><select id="territory.warehouse_id" wire:model="territory.warehouse_id" class="form-select"><option value="">None</option>@foreach ($warehouses as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select></x-ui.field>
                <x-ui.field label="Inside territory" for="territory.parent_id"><select id="territory.parent_id" wire:model="territory.parent_id" class="form-select"><option value="">None</option>@foreach ($territories as $p)@continue($p['id'] === $territory['id'])<option value="{{ $p['id'] }}">{{ $p['name'] }}</option>@endforeach</select></x-ui.field>
                <x-ui.field label="Description" for="territory.description" class="sm:col-span-2"><input id="territory.description" wire:model="territory.description" class="form-input"></x-ui.field>
                <x-ui.field label="Status" for="territory.status"><select id="territory.status" wire:model="territory.status" class="form-select"><option value="active">Active</option><option value="inactive">Inactive</option></select></x-ui.field>
            </form>
        @endif
        <x-slot:footer><button type="button" class="btn-light" @click="open = null">Cancel</button><button type="submit" form="terr-form" class="btn-primary">Save</button></x-slot:footer>
    </x-ui.modal>

    <x-ui.modal wire:model="members" :title="'Members of '.($members['name'] ?? '')" size="md">
        @if ($members)
            @error('members.members') <p class="mb-2 text-xs text-rose-600">{{ $message }}</p> @enderror
            <div class="space-y-2">
                @foreach ($members['rows'] as $i => $r)
                    <div class="grid grid-cols-[1fr_10rem_auto] gap-2" wire:key="mr-{{ $i }}">
                        <select wire:model="members.rows.{{ $i }}.user_id" class="form-select py-2" aria-label="Person"><option value="">Choose…</option>@foreach ($people as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select>
                        <select wire:model="members.rows.{{ $i }}.role" class="form-select py-2" aria-label="Role"><option value="representative">Representative</option><option value="supervisor">Supervisor</option></select>
                        <button type="button" class="p-2 text-slate-400 hover:text-rose-600" wire:click="removeMember({{ $i }})" aria-label="Remove"><x-heroicon-o-trash class="size-4"/></button>
                    </div>
                @endforeach
                <button type="button" class="link text-sm" wire:click="addMember">+ Add person</button>
            </div>
        @endif
        <x-slot:footer><button type="button" class="btn-light" @click="open = null">Cancel</button><button type="button" class="btn-primary" wire:click="saveMembers">Save members</button></x-slot:footer>
    </x-ui.modal>

    <x-ui.modal wire:model="assign" :title="'Assign customers to '.($assign['name'] ?? '')" size="lg">
        @if ($assign)
            <div class="mb-3 grid gap-3 sm:grid-cols-2">
                <x-ui.search model="customerSearch" placeholder="Find customers…"/>
                <select wire:model="assign.representative" class="form-select" aria-label="Representative"><option value="">Keep current representative</option>@foreach ($people as $id => $n)<option value="{{ $id }}">Representative: {{ $n }}</option>@endforeach</select>
            </div>
            @error('assign.customer_ids') <p class="mb-2 text-xs text-rose-600">{{ $message }}</p> @enderror
            <ul class="max-h-80 divide-y divide-slate-100 overflow-y-auto rounded-lg border border-slate-200 text-sm">
                @forelse ($customers as $c)
                    <li><label class="flex items-center gap-3 px-3 py-2 hover:bg-slate-50"><input type="checkbox" wire:model="assign.customer_ids.{{ $c['id'] }}" class="form-check">
                        <span class="flex-1">{{ $c['name'] }} <span class="text-slate-400">{{ $c['code'] }}</span></span><span class="text-xs text-slate-500">{{ $c['city'] ?? '' }}</span></label></li>
                @empty
                    <li class="px-3 py-3 text-slate-500">No customers found.</li>
                @endforelse
            </ul>
        @endif
        <x-slot:footer><button type="button" class="btn-light" @click="open = null">Cancel</button><button type="button" class="btn-primary" wire:click="saveAssign">Assign</button></x-slot:footer>
    </x-ui.modal>
</div>

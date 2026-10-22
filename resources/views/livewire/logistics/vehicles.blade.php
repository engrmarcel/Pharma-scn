<div>
    <x-ui.toolbar>
        <p class="text-sm text-slate-600">Cold-chain shipments should go in refrigerated vehicles.</p>
        <x-slot:actions>@if ($canManage)<button type="button" class="btn-primary" wire:click="create"><x-heroicon-o-plus class="size-4"/>Add vehicle</button>@endif</x-slot:actions>
    </x-ui.toolbar>
    <div class="card overflow-hidden">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Registration</th><th>Description</th><th>Refrigerated</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    @forelse ($vehicles as $v)
                        <tr wire:key="v-{{ $v['id'] }}"><td class="font-mono text-sm font-semibold">{{ $v['registration'] }}</td><td>{{ $v['description'] ?? '—' }}</td>
                            <td>@if ($v['refrigerated'])<x-ui.badge tone="blue">refrigerated</x-ui.badge>@else — @endif</td><td><x-ui.badge :status="$v['status']"/></td>
                            <td class="text-right">@if ($canManage)<button type="button" class="link text-xs" wire:click="edit(@js($v))">Edit</button>@endif</td></tr>
                    @empty
                        <x-ui.empty colspan="5" icon="wrench-screwdriver" title="No vehicles"/>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <x-ui.modal wire:model="vehicle" :title="($vehicle['id'] ?? null) ? 'Edit vehicle' : 'Add vehicle'" size="sm">
        @if ($vehicle)
            <form wire:submit="save" id="veh-form" class="space-y-4">
                <x-ui.field label="Registration" for="vehicle.registration"><input id="vehicle.registration" wire:model="vehicle.registration" class="form-input font-mono uppercase" @disabled($vehicle['id'])></x-ui.field>
                <x-ui.field label="Description" for="vehicle.description"><input id="vehicle.description" wire:model="vehicle.description" class="form-input" placeholder="Toyota Hiace van"></x-ui.field>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="vehicle.refrigerated" class="form-check">Refrigerated</label>
                @if ($vehicle['id'])<x-ui.field label="Status" for="vehicle.status"><select id="vehicle.status" wire:model="vehicle.status" class="form-select"><option value="active">Active</option><option value="inactive">Inactive</option></select></x-ui.field>@endif
            </form>
        @endif
        <x-slot:footer><button type="button" class="btn-light" @click="open = null">Cancel</button><button type="submit" form="veh-form" class="btn-primary">Save</button></x-slot:footer>
    </x-ui.modal>
</div>

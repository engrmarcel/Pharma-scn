<div>
    <x-ui.toolbar>
        <p class="text-sm text-slate-600">System accounts receive automatic postings from sales, purchasing, stock and payments, so they cannot be deactivated.</p>
        <x-slot:actions>@if ($canManage)<button type="button" class="btn-primary" wire:click="create"><x-heroicon-o-plus class="size-4"/>New account</button>@endif</x-slot:actions>
    </x-ui.toolbar>

    <div class="grid gap-6 lg:grid-cols-2">
        @foreach ($types as $type)
            <x-ui.card :title="ucfirst($type)" icon="list-bullet" :padded="false">
                <ul class="divide-y divide-slate-100">
                    @forelse ($groups[$type] ?? [] as $a)
                        <li class="flex items-center justify-between gap-3 px-5 py-2.5 text-sm" wire:key="a-{{ $a['id'] }}">
                            <span class="min-w-0"><span class="font-mono text-xs font-semibold text-navy-900">{{ $a['code'] }}</span> {{ $a['name'] }}
                                @if ($a['system_key'])<x-ui.badge tone="blue" class="ml-1">system</x-ui.badge>@endif</span>
                            <span class="flex items-center gap-3"><x-ui.badge :status="$a['status']"/>@if ($canManage)<button type="button" class="link text-xs" wire:click="edit(@js($a))">Edit</button>@endif</span>
                        </li>
                    @empty
                        <li class="px-5 py-4 text-sm text-slate-500">No {{ $type }} accounts.</li>
                    @endforelse
                </ul>
            </x-ui.card>
        @endforeach
    </div>

    <x-ui.modal wire:model="account" :title="($account['id'] ?? null) ? 'Edit account' : 'New account'" size="sm">
        @if ($account)
            <form wire:submit="save" id="acc-form" class="space-y-4">
                <x-ui.field label="Code" for="account.code"><input id="account.code" wire:model="account.code" class="form-input font-mono"></x-ui.field>
                <x-ui.field label="Name" for="account.name"><input id="account.name" wire:model="account.name" class="form-input"></x-ui.field>
                @if (! $account['id'])
                    <x-ui.field label="Type" for="account.type"><select id="account.type" wire:model="account.type" class="form-select">@foreach ($types as $t)<option value="{{ $t }}">{{ ucfirst($t) }}</option>@endforeach</select></x-ui.field>
                    <x-ui.field label="Parent (optional)" for="account.parent_id"><select id="account.parent_id" wire:model="account.parent_id" class="form-select"><option value="">None</option>@foreach ($parents as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach</select></x-ui.field>
                @else
                    <x-ui.field label="Status" for="account.status"><select id="account.status" wire:model="account.status" class="form-select" @disabled($account['system'] ?? false)><option value="active">Active</option><option value="inactive">Inactive</option></select></x-ui.field>
                @endif
            </form>
        @endif
        <x-slot:footer>
            <button type="button" class="btn-light" @click="open = null">Cancel</button>
            <button type="submit" form="acc-form" class="btn-primary">Save</button>
        </x-slot:footer>
    </x-ui.modal>
</div>

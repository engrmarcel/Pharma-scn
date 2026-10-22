<div>
    <x-ui.toolbar>
        <p class="text-sm text-slate-600">Rule segments are recalculated when refreshed; manual segments hold the customers you add.</p>
        <x-slot:actions>@if ($canManage)<button type="button" class="btn-primary" wire:click="create"><x-heroicon-o-plus class="size-4"/>New segment</button>@endif</x-slot:actions>
    </x-ui.toolbar>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @forelse ($segments as $s)
            <button type="button" wire:key="s-{{ $s['id'] }}" wire:click="$set('openId', '{{ $s['id'] }}')" class="card p-5 text-left transition hover:shadow-md">
                <div class="flex items-start justify-between gap-2">
                    <div><p class="font-mono text-xs text-gold-600">{{ $s['code'] }}</p><h3 class="font-semibold text-navy-950">{{ $s['name'] }}</h3></div>
                    <x-ui.badge :tone="$s['type'] === 'rule' ? 'blue' : 'slate'">{{ $s['type'] }}</x-ui.badge>
                </div>
                <p class="mt-2 text-3xl font-bold tabular-nums text-navy-950">{{ $s['members'] }}<span class="text-sm font-normal text-slate-500"> customers</span></p>
                <p class="mt-1 text-xs text-slate-500">{{ $s['status'] }}{{ $s['last_refreshed_at'] ? ' · refreshed '.fmt()->dateTime($s['last_refreshed_at']) : '' }}</p>
            </button>
        @empty
            <x-ui.card class="md:col-span-2 xl:col-span-3"><x-ui.empty icon="squares-plus" title="No segments yet"/></x-ui.card>
        @endforelse
    </div>

    <x-ui.modal wire:model="openId" :title="$detail['name'] ?? ''" size="lg">
        @if ($detail)
            @if ($detail['description'])<p class="mb-3 text-sm text-slate-600">{{ $detail['description'] }}</p>@endif
            @if ($detail['criteria'])
                <div class="mb-4 flex flex-wrap gap-2 text-xs">
                    @foreach ($detail['criteria'] as $k => $v)<span class="rounded-full bg-slate-100 px-3 py-1">{{ str_replace('_', ' ', $k) }}: {{ is_array($v) ? implode(', ', $v) : $v }}</span>@endforeach
                </div>
            @endif
            @if ($detail['type'] === 'manual' && $canManage)
                <div class="relative mb-3">
                    <x-ui.search model="customerSearch" placeholder="Add a customer…"/>
                    @if ($candidates->isNotEmpty())
                        <ul class="absolute z-20 mt-1 w-full max-w-md rounded-lg border border-slate-200 bg-white py-1 shadow-lg">
                            @foreach ($candidates as $c)<li><button type="button" class="block w-full px-3 py-1.5 text-left text-sm hover:bg-navy-50" wire:click="changeMember('{{ $c['id'] }}', true)">{{ $c['name'] }}</button></li>@endforeach
                        </ul>
                    @endif
                </div>
            @endif
            <ul class="max-h-80 divide-y divide-slate-100 overflow-y-auto rounded-lg border border-slate-200 text-sm">
                @forelse ($members as $m)
                    <li class="flex justify-between px-3 py-2"><span>{{ $m['name'] }} <span class="text-slate-400">{{ $m['city'] }}</span></span>
                        @if ($detail['type'] === 'manual' && $canManage)<button type="button" class="text-xs text-rose-700 hover:underline" wire:click="changeMember('{{ $m['id'] }}', false)">Remove</button>@endif</li>
                @empty
                    <li class="px-3 py-3 text-slate-500">No members{{ $detail['type'] === 'rule' ? ' yet: refresh the segment' : '' }}.</li>
                @endforelse
            </ul>
        @endif
        <x-slot:footer>
            @if ($detail && $canManage)
                @if ($detail['status'] === 'active')<button type="button" class="btn-light" wire:click="archive">Archive</button>@endif
                @if ($detail['type'] === 'rule')<button type="button" class="btn-primary" wire:click="refreshSegment"><x-heroicon-o-arrow-path class="size-4"/>Refresh</button>@endif
            @endif
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal wire:model="form" title="New segment" size="md">
        @if ($form)
            <form wire:submit="save" id="seg-form" class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-3">
                    <x-ui.field label="Code" for="form.code"><input id="form.code" wire:model="form.code" class="form-input font-mono uppercase"></x-ui.field>
                    <x-ui.field label="Name" for="form.name" class="sm:col-span-2"><input id="form.name" wire:model="form.name" class="form-input"></x-ui.field>
                </div>
                <x-ui.field label="Description" for="form.description"><input id="form.description" wire:model="form.description" class="form-input"></x-ui.field>
                <x-ui.field label="Type" for="form.type"><select id="form.type" wire:model.live="form.type" class="form-select"><option value="rule">By rule</option><option value="manual">By hand</option></select></x-ui.field>
                @if ($form['type'] === 'rule')
                    <div>
                        <p class="form-label">Customer types</p>
                        <div class="flex flex-wrap gap-x-4 gap-y-1">@foreach ($customerTypes as $t)<label class="flex items-center gap-1.5 text-sm"><input type="checkbox" value="{{ $t }}" wire:model="form.customer_types" class="form-check">{{ str_replace('_', ' ', $t) }}</label>@endforeach</div>
                    </div>
                    <div><p class="form-label">Tiers</p><div class="flex gap-4">@foreach (['A', 'B', 'C'] as $t)<label class="flex items-center gap-1.5 text-sm"><input type="checkbox" value="{{ $t }}" wire:model="form.tiers" class="form-check">{{ $t }}</label>@endforeach</div></div>
                    <div class="grid gap-4 sm:grid-cols-3">
                        <x-ui.field label="Cities" for="form.criteria.cities" hint="Comma separated"><input wire:model="form.cities" class="form-input"></x-ui.field>
                        <x-ui.field label="Not visited for (days)" for="form.criteria.not_visited_days"><input wire:model="form.not_visited_days" type="number" class="form-input"></x-ui.field>
                        <x-ui.field label="Revenue in 12 months, at least" for="form.criteria.min_revenue_12m"><input wire:model="form.min_revenue_12m" inputmode="decimal" class="form-input"></x-ui.field>
                    </div>
                @endif
            </form>
        @endif
        <x-slot:footer><button type="button" class="btn-light" @click="open = null">Cancel</button><button type="submit" form="seg-form" class="btn-primary">Create</button></x-slot:footer>
    </x-ui.modal>
</div>

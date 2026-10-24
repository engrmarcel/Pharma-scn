<div>
    <x-ui.toolbar>
        <x-ui.search placeholder="Supplier name or what they offer…"/>
        <x-ui.field label="Type" for="supplierType">
            <select id="supplierType" wire:model.live="supplierType" class="form-select w-48"><option value="">Any</option>@foreach ($types as $t)<option value="{{ $t->value }}">{{ $t->label() }}</option>@endforeach</select>
        </x-ui.field>
        <x-ui.field label="Delivers to" for="region"><input id="region" wire:model.live.debounce.400ms="region" class="form-input w-40" placeholder="State or region"></x-ui.field>
        <label class="flex items-center gap-2 pb-3 text-sm"><input type="checkbox" wire:model.live="verifiedOnly" class="form-check">Verified only</label>
    </x-ui.toolbar>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @forelse ($suppliers as $s)
            <div class="card flex flex-col overflow-hidden" wire:key="s-{{ $s['id'] }}">
                <div class="hero-surface relative px-5 py-4 text-white">
                    <div class="hex-pattern absolute inset-0 opacity-60" aria-hidden="true"></div>
                    <div class="relative flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <a href="{{ route('marketplace.storefront', $s['id']) }}" class="font-display text-lg font-bold hover:underline">{{ $s['name'] }}</a>
                            <p class="text-xs text-white/70">{{ $s['type_label'] }} · {{ $s['location'] }}</p>
                        </div>
                        <x-ui.badge :status="$s['verification']['status']" title="{{ $s['verification']['label'] }}">{{ str_replace('_', ' ', $s['verification']['status']) }}</x-ui.badge>
                    </div>
                </div>
                <div class="flex flex-1 flex-col p-5 text-sm">
                    @if ($s['headline'])<p class="font-medium text-navy-950">{{ $s['headline'] }}</p>@endif
                    <dl class="mt-3 grid grid-cols-2 gap-x-3 gap-y-1 text-xs">
                        <dt class="text-slate-500">Offers</dt><dd class="font-semibold">{{ $s['active_listings'] }}</dd>
                        <dt class="text-slate-500">Minimum order</dt><dd>{{ $s['minimum_order_value'] ? fmt()->money($s['minimum_order_value'], $s['currency_code'] ?? 'NGN') : 'None' }}</dd>
                        <dt class="text-slate-500">Delivers to</dt><dd>{{ implode(', ', $s['regions_served']) ?: '—' }}</dd>
                        <dt class="text-slate-500">Options</dt><dd>{{ implode(', ', $s['delivery_options']) ?: '—' }}</dd>
                    </dl>
                    @unless ($s['you_may_trade'])<p class="mt-3 text-xs text-rose-700">{{ $s['trade_block'] }}</p>@endunless
                    <div class="mt-auto flex flex-wrap items-center justify-end gap-2 pt-4">
                        <span class="mr-auto text-xs {{ $s['relationship'] === 'approved' ? 'text-emerald-700' : 'text-slate-500' }}">{{ ['approved' => 'Trading partner', 'requested' => 'Request pending', 'rejected' => 'Request declined', 'suspended' => 'Suspended'][$s['relationship']] ?? 'Not a partner yet' }}</span>
                        @if ($canRelate && ! $s['relationship'] && $s['you_may_trade'])<button type="button" class="btn-light btn-sm" wire:click="startRequest('{{ $s['id'] }}', @js($s['name']))">Request to trade</button>@endif
                        <a href="{{ route('marketplace.storefront', $s['id']) }}" class="btn-primary btn-sm">View storefront</a>
                    </div>
                </div>
            </div>
        @empty
            <x-ui.card class="md:col-span-2 xl:col-span-3"><x-ui.empty icon="building-storefront" title="No suppliers match">Try fewer filters.</x-ui.empty></x-ui.card>
        @endforelse
    </div>
    <div class="mt-4"><x-ui.pager :paginator="$meta"/></div>

    @include('livewire.marketplace.partials.request-trade')
</div>

@php
    $p = $supplier['profile'];
    $type = \App\Domain\Organizations\Enums\OrganizationType::tryFrom($supplier['type'])?->label();
    $relationship = $supplier['relationship'];
@endphp
<div class="space-y-6">
    <a href="{{ route('marketplace.suppliers') }}" class="link text-sm">&larr; All suppliers</a>

    @if ($own)
        <div class="rounded-xl border border-gold-300 bg-gold-50/60 px-4 py-3 text-sm text-navy-900">
            This is your storefront as buyers see it. Change it in <a href="{{ route('marketplace.profile') }}" class="link">Profile</a> and <a href="{{ route('marketplace.listings') }}" class="link">Listings</a>.
        </div>
    @endif

    {{-- Supplier header --}}
    <section class="hero-surface relative overflow-hidden rounded-2xl text-white shadow-lg">
        <div class="hex-pattern absolute inset-0" aria-hidden="true"></div>
        <div class="relative grid gap-6 p-6 lg:grid-cols-[1fr_auto] lg:items-end">
            <div>
                <p class="eyebrow text-gold-300">{{ $type }} · {{ collect([$supplier['city'], $supplier['state_region'], $supplier['country_code']])->filter()->implode(', ') }}</p>
                <h1 class="font-display mt-1 text-3xl font-bold">{{ $supplier['name'] }}</h1>
                @if ($p['headline'] ?? null)<p class="mt-2 max-w-2xl text-white/85">{{ $p['headline'] }}</p>@endif
                <p class="mt-3 inline-flex items-center gap-2 text-sm">
                    <x-ui.badge :status="$supplier['verification']['status']">{{ str_replace('_', ' ', $supplier['verification']['status']) }}</x-ui.badge>
                    <span class="text-white/70">{{ $supplier['verification']['label'] }}</span>
                </p>
            </div>
            @unless ($own)
                <div class="flex flex-col items-start gap-2 lg:items-end">
                    <span class="rounded-full px-3 py-1 text-sm font-medium {{ $relationship === 'approved' ? 'bg-emerald-400/20 text-emerald-100' : 'bg-white/10 text-white/80' }}">
                        {{ ['approved' => 'Your trading partner', 'requested' => 'Trading request pending', 'rejected' => 'Trading request declined', 'suspended' => 'Trading suspended'][$relationship] ?? 'Not a trading partner yet' }}
                    </span>
                    <div class="flex gap-2">
                        @if ($canRelate && ! $relationship && $supplier['you_may_trade'])<button type="button" class="btn-gold" wire:click="startRequest('{{ $supplier['id'] }}', @js($supplier['name']))">Request to trade</button>@endif
                        @if ($canRfq && $relationship === 'approved')<a href="{{ route('marketplace.rfqs', ['to' => $supplier['id']]) }}" class="btn-ghost-light">Ask for a quote</a>@endif
                    </div>
                    @unless ($supplier['you_may_trade'])<p class="max-w-xs text-xs text-rose-200">{{ $supplier['trade_block'] }}</p>@endunless
                </div>
            @endunless
        </div>
    </section>

    <div class="grid gap-6 xl:grid-cols-[1fr_20rem]">
        <div class="space-y-6">
            <div class="grid gap-4 md:grid-cols-[minmax(0,1fr)_18rem]">
                <x-ui.card title="About" icon="information-circle">
                    <p class="whitespace-pre-line text-sm text-slate-700">{{ $p['description'] ?: 'No description yet.' }}</p>
                </x-ui.card>
                <x-ui.card title="Trading terms" icon="truck">
                    <dl class="space-y-2 text-sm">
                        <div><dt class="text-xs text-slate-500">Delivers to</dt><dd>{{ implode(', ', $p['regions_served'] ?? []) ?: '—' }}</dd></div>
                        <div><dt class="text-xs text-slate-500">Options</dt><dd>{{ collect($p['delivery_options'] ?? [])->map(fn ($d) => ucfirst($d))->implode(', ') ?: '—' }}</dd></div>
                        <div><dt class="text-xs text-slate-500">Minimum order</dt><dd>{{ ($p['minimum_order_value'] ?? null) ? fmt()->money($p['minimum_order_value'], $p['currency_code'] ?? 'NGN') : 'None' }}</dd></div>
                        <div><dt class="text-xs text-slate-500">Contact</dt><dd class="break-all">{{ trim(($p['contact_email'] ?? '').' '.($p['contact_phone'] ?? '')) ?: '—' }}</dd></div>
                    </dl>
                </x-ui.card>
            </div>

            <div>
                <div class="mb-3 flex flex-wrap items-end justify-between gap-3">
                    <h2 class="text-lg font-semibold text-navy-950">Offers <span class="text-sm font-normal text-slate-500">({{ $supplier['active_listings'] }})</span></h2>
                    @unless ($own)<div class="w-full sm:w-72"><x-ui.search placeholder="Search this supplier's offers…"/></div>@endunless
                </div>
                @if ($own)
                    <div class="card overflow-hidden">
                        <div class="table-wrap">
                            <table class="data-table">
                                <thead><tr><th>Product</th><th>Pack</th><th class="num">Price</th><th>Min.</th><th>Availability</th><th>Buyers</th></tr></thead>
                                <tbody>
                                    @forelse ($ownListings as $l)
                                        <tr wire:key="ol-{{ $l['id'] }}"><td>{{ $names[$l['product_id']] ?? $l['product_id'] }}</td><td>{{ $l['package_level_code'] }}</td><td class="num">{{ fmt()->money($l['unit_price'], $l['currency_code']) }}</td>
                                            <td>{{ fmt()->number($l['minimum_order_quantity'], 0) }}</td><td>{{ $l['availability_mode'] === 'live' ? 'Live from stock' : str_replace('_', ' ', $l['declared_availability'] ?? '') }}</td>
                                            <td class="text-xs">{{ collect($l['allowed_buyer_types'] ?? [])->map(fn ($t) => \App\Domain\Organizations\Enums\OrganizationType::tryFrom($t)?->label())->filter()->implode(', ') ?: 'Everyone allowed' }}</td></tr>
                                    @empty
                                        <x-ui.empty colspan="6" icon="tag" title="No active listings"/>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                @else
                    <div class="grid gap-4 md:grid-cols-2">
                        @forelse ($offers as $o)
                            @include('livewire.marketplace.partials.offer', ['o' => $o, 'showSupplier' => false])
                        @empty
                            <x-ui.card class="md:col-span-2"><x-ui.empty icon="tag" title="No offers match">{{ $search ? 'Try another search.' : 'This supplier has no offers you can see right now.' }}</x-ui.empty></x-ui.card>
                        @endforelse
                    </div>
                @endif
            </div>
        </div>

        @unless ($own)
            <aside class="space-y-4 xl:sticky xl:top-24 xl:self-start">
                @include('livewire.marketplace.partials.cart')
            </aside>
        @endunless
    </div>

    @include('livewire.marketplace.partials.request-trade')
</div>

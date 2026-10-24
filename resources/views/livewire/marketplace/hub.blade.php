@php
    $buy = $a['buying'] ?? [];
    $sell = $a['selling'] ?? [];
    $org = $a['organization'] ?? null;
    $money = fn ($rows) => collect($rows)->map(fn ($r) => fmt()->money($r['amount'], $r['currency_code']))->implode(' · ') ?: fmt()->money(0, 'NGN');
    $count = fn ($rows) => collect($rows)->sum('orders');
    $buyItems = [
        ['awaiting_supplier', 'Orders waiting for the supplier', 'paper-airplane', route('marketplace.orders', ['as' => 'buyer', 'status' => 'submitted'])],
        ['shipments_to_receive', 'Deliveries to receive', 'truck', route('marketplace.orders', ['as' => 'buyer', 'status' => 'shipped'])],
        ['invoices_to_record', 'Supplier invoices to record', 'document-text', route('marketplace.orders', ['as' => 'buyer'])],
        ['quotes_to_review', 'Quotes to review', 'chat-bubble-left-right', route('marketplace.quotes')],
        ['awaiting_internal_approval', 'Orders waiting for your approval', 'shield-check', route('marketplace.orders', ['as' => 'buyer', 'status' => 'pending_approval'])],
        ['drafts', 'Draft orders', 'pencil-square', route('marketplace.orders', ['as' => 'buyer', 'status' => 'draft'])],
        ['open_rfqs', 'Your open requests for quotation', 'megaphone', route('marketplace.rfqs', ['as' => 'buyer'])],
        ['partner_requests_pending', 'Trading requests you sent, pending', 'link', route('marketplace.relationships', ['as' => 'buyer'])],
    ];
    $sellItems = [
        ['orders_to_accept', 'Orders to accept', 'inbox-arrow-down', route('marketplace.orders', ['as' => 'supplier', 'status' => 'submitted'])],
        ['orders_to_ship', 'Orders to ship', 'truck', route('marketplace.orders', ['as' => 'supplier', 'status' => 'accepted'])],
        ['rfqs_to_answer', 'Requests for quotation to answer', 'megaphone', route('marketplace.rfqs', ['as' => 'supplier'])],
        ['partner_requests_to_decide', 'Buyers asking to trade with you', 'user-plus', route('marketplace.relationships', ['as' => 'supplier'])],
    ];
    $attention = fn ($items, $data) => collect($items)->filter(fn ($i) => ($data[$i[0]] ?? 0) > 0);
    $kindIcon = ['order' => 'shopping-bag', 'quote' => 'chat-bubble-left-right', 'rfq' => 'megaphone', 'partner' => 'link', 'dispute' => 'scale'];
@endphp
<div class="grid gap-6 xl:grid-cols-[1fr_20rem]">
    <div class="space-y-6">
        @if (! $a)
            <x-ui.card><x-ui.empty icon="globe-europe-africa" title="The marketplace is not available">Your organization does not take part in the marketplace yet.</x-ui.empty></x-ui.card>
        @else
            {{-- Welcome and search --}}
            <section class="hero-surface relative overflow-hidden rounded-2xl text-white shadow-lg">
                <div class="hex-pattern absolute inset-0" aria-hidden="true"></div>
                <div class="relative p-6">
                    <p class="eyebrow text-gold-300">Trading floor</p>
                    <h2 class="font-display mt-1 text-2xl font-bold">{{ $org['name'] }}</h2>
                    <p class="mt-1 flex flex-wrap items-center gap-2 text-sm text-white/80">
                        @if ($org['can_buy'])<span class="rounded-full bg-white/10 px-2.5 py-0.5">Buys</span>@endif
                        @if ($org['can_sell'])<span class="rounded-full bg-white/10 px-2.5 py-0.5">Sells</span>@endif
                        <x-ui.badge :status="$org['verification']['status']">{{ str_replace('_', ' ', $org['verification']['status']) }}</x-ui.badge>
                        <span class="text-white/60">{{ $org['verification']['label'] }}</span>
                    </p>
                    @if ($canBrowse)
                        <form wire:submit="searchOffers" class="mt-5 flex max-w-2xl gap-2">
                            <label class="relative flex-1"><span class="sr-only">Search offers</span>
                                <x-heroicon-o-magnifying-glass class="pointer-events-none absolute left-3 top-1/2 size-5 -translate-y-1/2 text-slate-400"/>
                                <input wire:model="quickSearch" class="form-input w-full py-3 pl-10 text-base text-slate-800" placeholder="Find a medicine across all suppliers: name, ingredient or brand">
                            </label>
                            <button class="btn-gold">Search</button>
                        </form>
                        <div class="mt-4 flex flex-wrap gap-2 text-sm">
                            <a href="{{ route('marketplace.discover') }}" class="btn-ghost-light btn-sm">All offers</a>
                            <a href="{{ route('marketplace.discover', ['view' => 'compare']) }}" class="btn-ghost-light btn-sm">Compare prices</a>
                            <a href="{{ route('marketplace.suppliers') }}" class="btn-ghost-light btn-sm">Suppliers</a>
                            @if ($org['can_sell'] && $sell['listed'])<a href="{{ route('marketplace.storefront', $org['id']) }}" class="btn-ghost-light btn-sm">Your storefront</a>@endif
                        </div>
                    @endif
                </div>
            </section>

            {{-- Figures --}}
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                @if ($org['can_buy'])<x-ui.stat label="Purchases, last 30 days" :value="$money($a['last_30_days']['purchases'])" :hint="$count($a['last_30_days']['purchases']).' orders · '.$a['partners']['suppliers'].' suppliers'"/>@endif
                @if ($org['can_sell'])
                    <x-ui.stat label="Sales, last 30 days" :value="$money($a['last_30_days']['sales'])" :hint="$count($a['last_30_days']['sales']).' orders · '.$a['partners']['buyers'].' buyers'" tone="green"/>
                    <x-ui.stat label="Active offers" :value="$sell['active_listings']" :hint="$sell['listed'] ? 'Storefront listed' : 'Storefront not listed yet'" :tone="$sell['listed'] ? 'gold' : 'amber'"/>
                @endif
                <x-ui.stat label="Open disputes" :value="$a['open_disputes']" :tone="$a['open_disputes'] ? 'red' : 'green'"/>
            </div>

            {{-- Needs your attention --}}
            <div class="grid gap-4 {{ $org['can_sell'] && $org['can_buy'] ? 'lg:grid-cols-2' : '' }}">
                @if ($org['can_buy'])
                    <x-ui.card title="Buying: needs your attention" icon="shopping-cart" :padded="false">
                        <ul class="divide-y divide-slate-100">
                            @forelse ($attention($buyItems, $buy) as [$key, $label, $icon, $url])
                                <li><a href="{{ $url }}" class="flex items-center gap-3 px-5 py-3 text-sm hover:bg-slate-50"><x-dynamic-component :component="'heroicon-o-'.$icon" class="size-5 text-[var(--brand-primary)]"/><span class="flex-1">{{ $label }}</span><span class="rounded-full bg-[var(--brand-primary)] px-2.5 py-0.5 text-xs font-bold text-white">{{ $buy[$key] }}</span></a></li>
                            @empty
                                <li class="flex items-center gap-2 px-5 py-4 text-sm text-emerald-700"><x-heroicon-o-check-circle class="size-5"/> Nothing waiting on you.</li>
                            @endforelse
                        </ul>
                    </x-ui.card>
                @endif
                @if ($org['can_sell'])
                    <x-ui.card title="Selling: needs your attention" icon="building-storefront" :padded="false">
                        <ul class="divide-y divide-slate-100">
                            @forelse ($attention($sellItems, $sell) as [$key, $label, $icon, $url])
                                <li><a href="{{ $url }}" class="flex items-center gap-3 px-5 py-3 text-sm hover:bg-slate-50"><x-dynamic-component :component="'heroicon-o-'.$icon" class="size-5 text-[var(--brand-primary)]"/><span class="flex-1">{{ $label }}</span><span class="rounded-full bg-[var(--brand-primary)] px-2.5 py-0.5 text-xs font-bold text-white">{{ $sell[$key] }}</span></a></li>
                            @empty
                                <li class="flex items-center gap-2 px-5 py-4 text-sm text-emerald-700"><x-heroicon-o-check-circle class="size-5"/> Nothing waiting on you.</li>
                            @endforelse
                            @unless ($sell['listed'])
                                <li><a href="{{ route('marketplace.profile') }}" class="flex items-center gap-3 px-5 py-3 text-sm text-amber-800 hover:bg-amber-50"><x-heroicon-o-exclamation-triangle class="size-5"/><span class="flex-1">Publish your profile so buyers can find you</span><span class="text-xs">Profile &rarr;</span></a></li>
                            @endunless
                            @if ($sell['listed'] && ! $sell['active_listings'])
                                <li><a href="{{ route('marketplace.listings') }}" class="flex items-center gap-3 px-5 py-3 text-sm text-amber-800 hover:bg-amber-50"><x-heroicon-o-exclamation-triangle class="size-5"/><span class="flex-1">List products with price, minimum order and availability</span><span class="text-xs">Listings &rarr;</span></a></li>
                            @endif
                        </ul>
                    </x-ui.card>
                @endif
            </div>
            @if ($a['open_disputes'])<a href="{{ route('marketplace.disputes') }}" class="block rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">{{ $a['open_disputes'] }} open {{ \Illuminate\Support\Str::plural('dispute', $a['open_disputes']) }}: review them in Disputes &rarr;</a>@endif

            {{-- Recent activity --}}
            <x-ui.card title="Recent trading activity" icon="clock" :padded="false">
                <ol class="divide-y divide-slate-100">
                    @forelse ($a['recent'] as $e)
                        <li>
                            <a href="{{ route($e['link']['route'], $e['link']['params']) }}" class="flex items-start gap-3 px-5 py-3 text-sm hover:bg-slate-50">
                                <span class="mt-0.5 grid size-8 shrink-0 place-items-center rounded-full {{ $e['side'] === 'selling' ? 'bg-emerald-50 text-emerald-700' : 'bg-sky-50 text-sky-700' }}"><x-dynamic-component :component="'heroicon-o-'.($kindIcon[$e['kind']] ?? 'bolt')" class="size-4"/></span>
                                <span class="min-w-0 flex-1">
                                    <span class="font-medium text-navy-950">{{ $e['title'] }}</span>
                                    <span class="block text-xs text-slate-500">{{ $e['side'] === 'selling' ? 'Selling' : 'Buying' }}@if ($e['counterparty']) · with {{ $e['counterparty'] }}@endif · {{ fmt()->dateTime($e['at']) }}</span>
                                </span>
                                @if ($e['amount'] !== null)<span class="shrink-0 font-mono text-xs">{{ fmt()->money($e['amount'], $e['currency_code']) }}</span>@endif
                                <x-ui.badge :status="$e['status']">{{ str_replace('_', ' ', $e['status']) }}</x-ui.badge>
                            </a>
                        </li>
                    @empty
                        <li class="px-5 py-6 text-center text-sm text-slate-500">No trading yet. Find suppliers and send your first order or request for quotation.</li>
                    @endforelse
                </ol>
            </x-ui.card>

            {{-- Featured suppliers --}}
            @if ($featured !== [])
                <div>
                    <div class="mb-3 flex items-end justify-between"><h2 class="text-lg font-semibold text-navy-950">Verified suppliers</h2><a href="{{ route('marketplace.suppliers') }}" class="link text-sm">All suppliers &rarr;</a></div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        @foreach ($featured as $s)
                            <a href="{{ route('marketplace.storefront', $s['id']) }}" class="card flex items-center gap-3 p-4 transition hover:shadow-md" wire:key="f-{{ $s['id'] }}">
                                <span class="grid size-11 shrink-0 place-items-center rounded-xl bg-navy-50 text-[var(--brand-primary)]"><x-heroicon-o-building-storefront class="size-6"/></span>
                                <span class="min-w-0 flex-1"><span class="block truncate font-semibold text-navy-950">{{ $s['name'] }}</span><span class="block text-xs text-slate-500">{{ $s['type_label'] }} · {{ $s['active_listings'] }} offers</span></span>
                                <span class="text-xs {{ $s['relationship'] === 'approved' ? 'text-emerald-700' : 'text-slate-400' }}">{{ $s['relationship'] === 'approved' ? 'Partner' : '' }}</span>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif
        @endif
    </div>

    <aside class="space-y-4 xl:sticky xl:top-24 xl:self-start">
        @if ($canBrowse)@include('livewire.marketplace.partials.cart')@endif
    </aside>
</div>

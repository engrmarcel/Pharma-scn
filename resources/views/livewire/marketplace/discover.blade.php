<div class="grid gap-6 xl:grid-cols-[1fr_20rem]">
    <div>
        <x-ui.toolbar>
            <x-ui.search placeholder="Product, ingredient or brand…"/>
            <x-ui.field label="Supplier type" for="supplierType">
                <select id="supplierType" wire:model.live="supplierType" class="form-select w-48"><option value="">Any</option>@foreach ($types as $t)<option value="{{ $t->value }}">{{ $t->label() }}</option>@endforeach</select>
            </x-ui.field>
            <label class="flex items-center gap-2 pb-3 text-sm"><input type="checkbox" wire:model.live="verifiedOnly" class="form-check">Verified suppliers</label>
            <label class="flex items-center gap-2 pb-3 text-sm"><input type="checkbox" wire:model.live="availableOnly" class="form-check">Available now</label>
            <x-slot:actions>
                <div class="inline-flex rounded-lg border border-slate-200 bg-white p-0.5 text-sm" role="group" aria-label="View">
                    <button type="button" wire:click="$set('view', 'offers')" class="rounded-md px-3 py-1.5 {{ $view === 'offers' ? 'bg-[var(--brand-primary)] text-white' : 'text-slate-600' }}">Offers</button>
                    <button type="button" wire:click="$set('view', 'compare')" class="rounded-md px-3 py-1.5 {{ $view === 'compare' ? 'bg-[var(--brand-primary)] text-white' : 'text-slate-600' }}">Compare by product</button>
                </div>
            </x-slot:actions>
        </x-ui.toolbar>

        @if ($view === 'compare')
            <div class="space-y-4">
                @forelse ($compare as $g)
                    <x-ui.card :title="$g['product']['display_name']" icon="scale" :padded="false" wire:key="g-{{ $g['product']['id'] }}">
                        <div class="table-wrap">
                            <table class="data-table">
                                <thead><tr><th>Supplier</th><th>Pack</th><th class="num">Price</th><th class="num">Per unit</th><th>Min.</th><th>Availability</th><th>Lead time</th><th></th></tr></thead>
                                <tbody>
                                    @foreach ($g['offers'] as $o)
                                        <tr wire:key="c-{{ $o['listing_id'] }}" class="{{ $g['best'] === $o['listing_id'] ? 'bg-emerald-50/60' : '' }}">
                                            <td><a href="{{ route('marketplace.storefront', $o['supplier']['id']) }}" class="link">{{ $o['supplier']['name'] }}</a>@if ($g['best'] === $o['listing_id'])<span class="ml-1 rounded bg-emerald-100 px-1.5 text-[11px] font-semibold text-emerald-800">Best price</span>@endif
                                                @unless ($o['restriction']['you_may_buy'])<span class="block text-xs text-rose-700">{{ $o['restriction']['reason'] }}</span>@endunless</td>
                                            <td>{{ $o['pack']['name'] }}</td>
                                            <td class="num font-semibold">{{ fmt()->money($o['unit_price'], $o['currency_code']) }}</td>
                                            <td class="num">{{ $o['price_per_base_unit'] !== null ? fmt()->money($o['price_per_base_unit'], $o['currency_code']).' / '.($o['pack']['base_unit'] ?? 'unit') : '—' }}</td>
                                            <td>{{ fmt()->number($o['minimum_order_quantity'], 0) }}</td>
                                            <td>{{ str_replace('_', ' ', $o['availability']['status'] ?? '') }}</td>
                                            <td>{{ $o['lead_time_days'] !== null ? $o['lead_time_days'].' d' : '—' }}</td>
                                            <td class="text-right">@if ($canOrder && $o['restriction']['you_may_buy'])<button type="button" class="btn-primary btn-sm" wire:click="add(@js($o))">Add</button>@endif</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </x-ui.card>
                @empty
                    <x-ui.card><x-ui.empty icon="magnifying-glass" title="No offers match">Try fewer filters, or ask suppliers for a quote with an RFQ.</x-ui.empty></x-ui.card>
                @endforelse
                <p class="text-xs text-slate-500">Compared within this page of results; search for a product to compare all its offers. "Per unit" divides the pack price by the tablets, capsules or sachets it holds.</p>
            </div>
        @else
            <div class="grid gap-4 md:grid-cols-2">
                @forelse ($offers as $o)
                    @include('livewire.marketplace.partials.offer', ['o' => $o])
                @empty
                    <x-ui.card class="md:col-span-2"><x-ui.empty icon="magnifying-glass" title="No offers match">Try fewer filters, or ask suppliers for a quote with an RFQ.</x-ui.empty></x-ui.card>
                @endforelse
            </div>
        @endif
        <div class="mt-4"><x-ui.pager :paginator="$meta"/></div>
    </div>

    <aside class="space-y-4 xl:sticky xl:top-24 xl:self-start">
        @include('livewire.marketplace.partials.cart')
    </aside>

    @include('livewire.marketplace.partials.request-trade')
</div>

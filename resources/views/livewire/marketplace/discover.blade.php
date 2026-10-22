<div class="grid gap-6 xl:grid-cols-[1fr_20rem]">
    <div>
        <x-ui.toolbar>
            <x-ui.search placeholder="Product, ingredient or brand…"/>
            <x-ui.field label="Supplier type" for="supplierType">
                <select id="supplierType" wire:model.live="supplierType" class="form-select w-48"><option value="">Any</option>@foreach ($types as $t)<option value="{{ $t->value }}">{{ $t->label() }}</option>@endforeach</select>
            </x-ui.field>
            <label class="flex items-center gap-2 pb-3 text-sm"><input type="checkbox" wire:model.live="verifiedOnly" class="form-check">Verified suppliers</label>
            <label class="flex items-center gap-2 pb-3 text-sm"><input type="checkbox" wire:model.live="availableOnly" class="form-check">Available now</label>
        </x-ui.toolbar>

        <div class="grid gap-4 md:grid-cols-2">
            @forelse ($offers as $o)
                <div class="card flex flex-col p-5" wire:key="o-{{ $o['listing_id'] }}">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h3 class="font-semibold text-navy-950">{{ $o['product']['display_name'] }}</h3>
                            <p class="text-xs text-slate-500">{{ $o['pack']['name'] }} · {{ $o['product']['platform_sku'] }}</p>
                        </div>
                        <p class="shrink-0 text-right text-lg font-bold tabular-nums text-navy-950">{{ fmt()->money($o['unit_price'], $o['currency_code']) }}<span class="block text-xs font-normal text-slate-500">per {{ $o['pack']['code'] }}</span></p>
                    </div>
                    <button type="button" class="mt-3 flex items-center gap-2 text-left text-sm" wire:click="$set('supplierId', '{{ $o['supplier']['id'] }}')">
                        <x-heroicon-o-building-office-2 class="size-4 text-slate-400"/><span class="link">{{ $o['supplier']['name'] }}</span>
                        <x-ui.badge :status="$o['supplier']['verification']['status']" title="{{ $o['supplier']['verification']['label'] }}">{{ str_replace('_', ' ', $o['supplier']['verification']['status']) }}</x-ui.badge>
                    </button>
                    <div class="mt-2 flex flex-wrap gap-2 text-xs text-slate-500">
                        <span>Min. {{ fmt()->number($o['minimum_order_quantity'], 0) }}</span>
                        @if ($o['lead_time_days'] !== null)<span>· {{ $o['lead_time_days'] }} days lead time</span>@endif
                        <span>· {{ str_replace('_', ' ', $o['availability']['status'] ?? '') }}{{ ($o['availability']['stale'] ?? false) ? ' (not recently confirmed)' : '' }}</span>
                    </div>
                    @if ($o['restriction']['restricted'])<p class="mt-2 text-xs text-amber-700">{{ $o['restriction']['classification'] }}: {{ $o['restriction']['you_may_buy'] ? 'you may buy this' : $o['restriction']['reason'] }}</p>@endif
                    <div class="mt-auto flex flex-wrap items-center justify-end gap-2 pt-4">
                        @if ($o['relationship'] !== 'approved')
                            <span class="mr-auto text-xs text-slate-500">{{ $o['relationship'] ? 'Trading request: '.$o['relationship'] : 'No trading relationship yet' }}</span>
                            @if ($canRelate && ! $o['relationship'])<button type="button" class="btn-light btn-sm" wire:click="startRequest('{{ $o['supplier']['id'] }}', @js($o['supplier']['name']))">Request to trade</button>@endif
                        @endif
                        @if ($canOrder && $o['restriction']['you_may_buy'])<button type="button" class="btn-primary btn-sm" wire:click="add(@js($o))"><x-heroicon-o-plus class="size-4"/>Add</button>@endif
                    </div>
                </div>
            @empty
                <x-ui.card class="md:col-span-2"><x-ui.empty icon="magnifying-glass" title="No offers match">Try fewer filters, or ask suppliers for a quote with an RFQ.</x-ui.empty></x-ui.card>
            @endforelse
        </div>
        <div class="mt-4"><x-ui.pager :paginator="$meta"/></div>
    </div>

    <aside class="space-y-4 xl:sticky xl:top-24 xl:self-start">
        <x-ui.card title="Your orders in progress" icon="shopping-cart">
            @forelse ($cart as $sid => $c)
                <div class="mb-4 last:mb-0" wire:key="cart-{{ $sid }}">
                    <p class="text-sm font-semibold text-navy-950">{{ $c['name'] }}</p>
                    @foreach ($c['lines'] as $lid => $l)
                        <div class="mt-2 flex items-center gap-2 text-sm" wire:key="cl-{{ $lid }}">
                            <span class="min-w-0 flex-1 truncate" title="{{ $l['name'] }}">{{ $l['name'] }}</span>
                            <input wire:model="cart.{{ $sid }}.lines.{{ $lid }}.quantity" inputmode="decimal" class="form-input w-16 py-1 text-right" aria-label="Quantity">
                            <button type="button" class="text-slate-400 hover:text-rose-600" wire:click="removeLine('{{ $sid }}', '{{ $lid }}')" aria-label="Remove"><x-heroicon-o-x-mark class="size-4"/></button>
                        </div>
                    @endforeach
                    <button type="button" class="btn-primary btn-sm mt-3 w-full" wire:click="startCheckout('{{ $sid }}')">Order from {{ $c['name'] }}</button>
                </div>
            @empty
                <p class="text-sm text-slate-500">Add offers to build an order. Each supplier gets its own order.</p>
            @endforelse
        </x-ui.card>
    </aside>

    <x-ui.modal wire:model="checkout" title="Place the order" size="md">
        @if ($checkout)
            <form wire:submit="placeOrder" id="co-form" class="space-y-4">
                <p class="text-sm text-slate-600">To {{ $cart[$checkout['supplier_id']]['name'] ?? '' }}: {{ count($cart[$checkout['supplier_id']]['lines'] ?? []) }} lines.</p>
                @error('checkout.lines') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
                <x-ui.field label="Deliver to" for="checkout.delivery_warehouse_id"><select id="checkout.delivery_warehouse_id" wire:model="checkout.delivery_warehouse_id" class="form-select">@foreach ($warehouses as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select></x-ui.field>
                <x-ui.field label="Needed by" for="checkout.requested_date"><input id="checkout.requested_date" type="date" wire:model="checkout.requested_date" class="form-input"></x-ui.field>
                <x-ui.field label="Notes for the supplier" for="checkout.notes"><textarea id="checkout.notes" wire:model="checkout.notes" rows="2" class="form-input"></textarea></x-ui.field>
                <p class="text-xs text-slate-500">The order is sent to the supplier. If your organization needs internal approval first, it waits for that.</p>
            </form>
        @endif
        <x-slot:footer><button type="button" class="btn-light" @click="open = null">Cancel</button><button type="submit" form="co-form" class="btn-primary">Send order</button></x-slot:footer>
    </x-ui.modal>

    <x-ui.modal wire:model="requesting" :title="'Request to trade with '.($requesting['name'] ?? '')" size="sm">
        @if ($requesting)
            <form wire:submit="sendRequest" id="rq-form"><x-ui.field label="Message (optional)" for="requesting.message"><textarea id="requesting.message" wire:model="requesting.message" rows="3" class="form-input" placeholder="Who you are and what you would like to buy"></textarea></x-ui.field></form>
        @endif
        <x-slot:footer><button type="button" class="btn-light" @click="open = null">Cancel</button><button type="submit" form="rq-form" class="btn-primary">Send request</button></x-slot:footer>
    </x-ui.modal>

    <x-ui.modal wire:model="supplierId" :title="$supplier['name'] ?? 'Supplier'" size="md">
        @if ($supplier)
            <p class="text-sm text-slate-600">{{ \App\Domain\Organizations\Enums\OrganizationType::tryFrom($supplier['type'])?->label() }} · {{ collect([$supplier['city'], $supplier['state_region'], $supplier['country_code']])->filter()->implode(', ') }}</p>
            <p class="mt-1 text-xs text-slate-500"><x-ui.badge :status="$supplier['verification']['status']"/> {{ $supplier['verification']['label'] }}</p>
            @if ($supplier['profile']['headline'] ?? null)<p class="mt-4 font-semibold text-navy-950">{{ $supplier['profile']['headline'] }}</p>@endif
            @if ($supplier['profile']['description'] ?? null)<p class="mt-1 whitespace-pre-line text-sm text-slate-700">{{ $supplier['profile']['description'] }}</p>@endif
            <x-ui.dl class="mt-4" :items="['Delivers' => implode(', ', $supplier['profile']['delivery_options'] ?? []), 'Regions' => implode(', ', $supplier['profile']['regions_served'] ?? []),
                'Minimum order' => ($supplier['profile']['minimum_order_value'] ?? null) ? fmt()->money($supplier['profile']['minimum_order_value'], $supplier['profile']['currency_code'] ?? 'NGN') : null,
                'Contact' => trim(($supplier['profile']['contact_email'] ?? '').' '.($supplier['profile']['contact_phone'] ?? ''))]"/>
        @endif
    </x-ui.modal>
</div>

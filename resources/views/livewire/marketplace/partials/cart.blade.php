{{-- The buyer's cart (UsesMarketplaceCart): one order per supplier. Needs $cart, $warehouses, $canOrder, $checkout. --}}
<x-ui.card title="Your order" icon="shopping-cart">
    @forelse ($cart as $sid => $c)
        @php $total = collect($c['lines'])->reduce(fn ($s, $l) => bcadd($s, bcmul((string) $l['price'], (string) $l['quantity'], 4), 4), '0'); $currency = collect($c['lines'])->first()['currency'] ?? 'NGN'; @endphp
        <div class="mb-4 border-b border-slate-100 pb-4 last:mb-0 last:border-0 last:pb-0" wire:key="cart-{{ $sid }}">
            <a href="{{ route('marketplace.storefront', $sid) }}" class="text-sm font-semibold text-navy-950 hover:underline">{{ $c['name'] }}</a>
            @foreach ($c['lines'] as $lid => $l)
                <div class="mt-2 text-sm" wire:key="cl-{{ $lid }}">
                    <div class="flex items-center gap-2">
                        <span class="min-w-0 flex-1 truncate" title="{{ $l['name'] }} · {{ $l['pack'] }}">{{ $l['name'] }}</span>
                        <input value="{{ rtrim(rtrim((string) $l['quantity'], '0'), '.') }}" wire:change="setCartQuantity('{{ $sid }}', '{{ $lid }}', $event.target.value)" inputmode="decimal" class="form-input w-16 py-1 text-right" aria-label="Quantity of {{ $l['name'] }}">
                        <button type="button" class="text-slate-400 hover:text-rose-600" wire:click="removeLine('{{ $sid }}', '{{ $lid }}')" aria-label="Remove {{ $l['name'] }}"><x-heroicon-o-x-mark class="size-4"/></button>
                    </div>
                    <p class="text-xs text-slate-500">{{ $l['pack'] }} × {{ fmt()->money($l['price'], $l['currency']) }}@if ((float) $l['moq'] > 1) · min. {{ fmt()->number($l['moq'], 0) }}@endif</p>
                </div>
            @endforeach
            <p class="mt-2 flex justify-between text-sm"><span class="text-slate-500">At list prices</span><span class="font-semibold tabular-nums">{{ fmt()->money($total, $currency) }}</span></p>
            @if ($canOrder)<button type="button" class="btn-primary btn-sm mt-2 w-full" wire:click="startCheckout('{{ $sid }}')">Order from {{ $c['name'] }}</button>@endif
        </div>
    @empty
        <p class="text-sm text-slate-500">Add offers to build an order. Each supplier gets its own order; your cart stays while you browse.</p>
    @endforelse
</x-ui.card>

<x-ui.modal wire:model="checkout" title="Place the order" size="md">
    @if ($checkout)
        <form wire:submit="placeOrder" id="co-form" class="space-y-4">
            <p class="text-sm text-slate-600">To {{ $cart[$checkout['supplier_id']]['name'] ?? '' }}: {{ count($cart[$checkout['supplier_id']]['lines'] ?? []) }} lines.</p>
            @error('checkout.lines') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
            <x-ui.field label="Deliver to" for="checkout.delivery_warehouse_id"><select id="checkout.delivery_warehouse_id" wire:model="checkout.delivery_warehouse_id" class="form-select">@foreach ($warehouses as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select></x-ui.field>
            <x-ui.field label="Needed by" for="checkout.requested_date"><input id="checkout.requested_date" type="date" wire:model="checkout.requested_date" class="form-input"></x-ui.field>
            <x-ui.field label="Notes for the supplier" for="checkout.notes"><textarea id="checkout.notes" wire:model="checkout.notes" rows="2" class="form-input"></textarea></x-ui.field>
            <p class="text-xs text-slate-500">The supplier's current prices and minimums apply. The order is sent to the supplier; if your organization needs internal approval first, it waits for that.</p>
        </form>
    @endif
    <x-slot:footer><button type="button" class="btn-light" @click="open = null">Cancel</button><button type="submit" form="co-form" class="btn-primary">Send order</button></x-slot:footer>
</x-ui.modal>

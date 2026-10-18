<div class="space-y-4">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-semibold">Register</h1>
        @if ($shift)
            <div class="text-sm text-slate-600">
                Shift <span class="font-mono">{{ $shift->number }}</span> · drawer <span class="font-mono">{{ $drawer }}</span>
            </div>
        @endif
    </div>

    @error('register')
        <div role="alert" class="rounded border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-800">{{ $message }}</div>
    @enderror

    @if (! $shift)
        <section class="max-w-md rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="mb-3 font-semibold">Open a shift</h2>
            <label class="mb-2 block text-sm">
                <span class="mb-1 block">Terminal</span>
                <select wire:model="terminalId" class="w-full rounded border border-slate-300 px-3 py-2">
                    <option value="">Choose…</option>
                    @foreach ($terminals as $t)
                        <option value="{{ $t->id }}">{{ $t->code }} — {{ $t->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="mb-3 block text-sm">
                <span class="mb-1 block">Opening float</span>
                <input wire:model="openingFloat" inputmode="decimal" class="w-full rounded border border-slate-300 px-3 py-2">
            </label>
            <button wire:click="openShift" class="rounded bg-[var(--brand-primary)] px-4 py-2 font-medium text-white">Open shift</button>
        </section>
    @else
        <div class="grid gap-4 lg:grid-cols-5">
            <section class="lg:col-span-3 space-y-3">
                <label class="block">
                    <span class="sr-only">Scan or search</span>
                    <input wire:model.live.debounce.250ms="search" wire:keydown.enter="scan" autofocus
                           placeholder="Scan barcode / batch, or type a name (Enter adds an exact match)"
                           class="w-full rounded border border-slate-300 px-3 py-3 text-lg">
                </label>

                @if ($results->isNotEmpty())
                    <ul class="divide-y rounded-lg border border-slate-200 bg-white text-sm shadow-sm">
                        @foreach ($results as $r)
                            <li wire:key="r-{{ $r->product_id }}" class="flex items-center justify-between px-3 py-2">
                                <div>
                                    <div class="font-medium">{{ $r->display_name }}</div>
                                    <div class="text-xs text-slate-500">{{ $r->sku }} · sellable {{ rtrim(rtrim($r->sellable_base_units, '0'), '.') ?: 0 }} units</div>
                                </div>
                                <button wire:click="add('{{ $r->product_id }}')" class="rounded border border-slate-300 px-3 py-1 hover:bg-slate-50" @disabled($r->sellable_base_units <= 0)>Add</button>
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if ($held->isNotEmpty())
                    <div class="text-sm">
                        <span class="text-slate-600">Held:</span>
                        @foreach ($held as $h)
                            <button wire:click="resume('{{ $h->id }}')" class="ml-2 rounded border border-slate-300 px-2 py-0.5">{{ $h->number }} ({{ $h->total }})</button>
                        @endforeach
                    </div>
                @endif

                <details class="rounded border border-slate-200 bg-white p-3 text-sm">
                    <summary class="cursor-pointer font-medium">Close shift</summary>
                    <div class="mt-2 flex gap-2">
                        <input wire:model="countedCash" inputmode="decimal" placeholder="Counted cash" class="rounded border border-slate-300 px-3 py-2">
                        <button wire:click="closeShift" wire:confirm="Close this shift?" class="rounded border border-slate-300 px-3 py-2">Close shift</button>
                    </div>
                </details>
            </section>

            <section class="lg:col-span-2 rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                @if ($completed)
                    <div class="space-y-3 text-center">
                        <p class="text-lg font-semibold text-emerald-700">Sale {{ $completed->number }} completed</p>
                        <p>Change due: <span class="font-mono text-2xl">{{ $completed->change_due }}</span></p>
                        <a href="{{ route('pos.receipt', $completed->id) }}" target="_blank" class="inline-block rounded border border-slate-300 px-3 py-2">Print receipt</a>
                        <button wire:click="newSale" class="rounded bg-[var(--brand-primary)] px-4 py-2 text-white">New sale</button>
                    </div>
                @else
                    <h2 class="mb-2 font-semibold">Cart @if ($sale) <span class="text-xs text-slate-500">{{ $sale->number }} · {{ str_replace('_', ' ', $sale->status) }}</span> @endif</h2>
                    @forelse ($cart as $i => $line)
                        @php($priced = $sale?->lines->values()[$i] ?? null)
                        <div wire:key="c-{{ $i }}" class="border-b border-slate-100 py-2 text-sm">
                            <div class="flex items-center justify-between gap-2">
                                <span class="font-medium">{{ $line['name'] }}</span>
                                <button wire:click="remove({{ $i }})" class="text-red-600" aria-label="Remove {{ $line['name'] }}" @disabled($sale?->status === 'awaiting_payment')>✕</button>
                            </div>
                            <div class="mt-1 flex items-center justify-between">
                                <input value="{{ $line['quantity'] }}" wire:change="setQuantity({{ $i }}, $event.target.value)" inputmode="decimal" aria-label="Quantity"
                                       class="w-20 rounded border border-slate-300 px-2 py-1" @disabled($sale?->status === 'awaiting_payment')>
                                <span class="text-slate-500">{{ $line['package_level_code'] }} × {{ $priced?->unit_price }}</span>
                                <span class="font-mono">{{ $priced?->line_total }}</span>
                            </div>
                            @if ($priced?->prescription)
                                <p class="mt-1 text-xs text-amber-700">Dispensed against prescription {{ $priced->prescription['reference'] }}</p>
                            @endif
                        </div>
                    @empty
                        <p class="text-sm text-slate-500">Scan or search to add items.</p>
                    @endforelse

                    @if ($sale)
                        <dl class="mt-3 space-y-1 text-sm">
                            <div class="flex justify-between"><dt>Subtotal</dt><dd class="font-mono">{{ $sale->subtotal }}</dd></div>
                            <div class="flex justify-between"><dt>Discount</dt><dd class="font-mono">{{ $sale->discount_total }}</dd></div>
                            <div class="flex justify-between"><dt>Tax</dt><dd class="font-mono">{{ $sale->tax_total }}</dd></div>
                            <div class="flex justify-between text-lg font-semibold"><dt>Total</dt><dd class="font-mono">{{ $sale->total }}</dd></div>
                        </dl>

                        @if ($sale->status === 'open')
                            <div class="mt-3 flex gap-2">
                                <button wire:click="checkout" class="flex-1 rounded bg-[var(--brand-primary)] px-4 py-2 font-medium text-white">Checkout</button>
                                <button wire:click="hold" class="rounded border border-slate-300 px-3 py-2">Hold</button>
                                <button wire:click="cancel" wire:confirm="Cancel this sale?" class="rounded border border-red-300 px-3 py-2 text-red-700">Cancel</button>
                            </div>
                        @elseif ($sale->status === 'awaiting_payment')
                            <p class="mt-3 text-sm">Outstanding: <span class="font-mono font-semibold">{{ $outstanding }}</span></p>
                            @foreach ($sale->payments as $p)
                                <p class="text-xs text-slate-600">{{ $p->method }} {{ $p->amount }} — <span class="{{ $p->status === 'pending' ? 'text-amber-700' : '' }}">{{ $p->status }}</span></p>
                            @endforeach
                            @if (bccomp($outstanding, '0', 4) > 0)
                                <div class="mt-2 flex gap-2">
                                    <input wire:model="tendered" wire:keydown.enter="payCash" inputmode="decimal" placeholder="Cash tendered" class="flex-1 rounded border border-slate-300 px-3 py-2">
                                    <button wire:click="payCash" class="rounded bg-[var(--brand-primary)] px-3 py-2 text-white">Cash</button>
                                </div>
                                <div class="mt-2 flex gap-2">
                                    <input wire:model="cardApprovalCode" placeholder="Card terminal approval code" class="flex-1 rounded border border-slate-300 px-3 py-2">
                                    <button wire:click="payCard" class="rounded border border-slate-300 px-3 py-2">Card</button>
                                </div>
                            @else
                                <p class="mt-2 text-sm text-amber-700">Waiting for electronic payment confirmation (supervisor or provider).</p>
                            @endif
                            <button wire:click="cancel" wire:confirm="Cancel this sale?" class="mt-2 text-sm text-red-700">Cancel sale</button>
                        @endif
                    @endif
                @endif
            </section>
        </div>
    @endif
</div>

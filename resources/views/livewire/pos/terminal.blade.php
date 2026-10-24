@php
    $money = fn ($v) => $currency !== '' ? fmt()->money($v ?? 0, $currency) : fmt()->number($v ?? 0, 2);
    $status = $sale['status'] ?? null;
    $paying = $status === 'awaiting_payment';
    $saleLines = collect($sale['lines'] ?? [])->values();
    $quick = [];
    if ($paying && bccomp($outstanding, '0', 4) > 0) {
        foreach ([100, 500, 1000, 5000, 10000] as $step) {
            $v = (string) (ceil((float) $outstanding / $step) * $step);
            if (bccomp($v, $outstanding, 4) > 0 && ! in_array($v, $quick, true)) {
                $quick[] = $v;
            }
        }
        $quick = array_slice($quick, 0, 4);
    }
    $method = collect($paymentMethods)->firstWhere('channel', $payMethod);
@endphp
<div class="flex h-full flex-col"
     x-data="{
        online: navigator.onLine,
        now: new Date(),
        autoPrint: (() => { try { return localStorage.getItem('pos.autoPrint') !== '0' } catch { return true } })(),
        setAutoPrint(v) { this.autoPrint = v; try { localStorage.setItem('pos.autoPrint', v ? '1' : '0') } catch {} },
        key(e) {
            const map = { F2: 'search', F4: 'customer', F6: 'sales', F7: 'held', F8: 'hold', F9: 'pay' };
            if (e.key === 'Escape' && $wire.dialog) { e.preventDefault(); $wire.set('dialog', null); return; }
            if (! map[e.key]) return;
            e.preventDefault();
            ({
                search: () => { $wire.newSale(); window.dispatchEvent(new CustomEvent('pos-focus-search')) },
                customer: () => $wire.set('dialog', 'customer'),
                sales: () => $wire.set('dialog', 'sales'),
                held: () => $wire.set('dialog', 'held'),
                hold: () => $wire.hold(),
                pay: () => document.getElementById('pos-primary')?.click(),
            })[map[e.key]]();
        },
     }"
     x-init="setInterval(() => now = new Date(), 15000)"
     @online.window="online = true" @offline.window="online = false"
     @keydown.window="key($event)"
     @pos-sale-completed.window="if (autoPrint) $refs.printer.src = $event.detail.receipt">

    {{-- Top bar --}}
    <header class="flex flex-wrap items-center gap-x-5 gap-y-1 bg-[var(--brand-secondary)] px-4 py-2 text-sm text-white/80">
        <span class="font-display text-base font-bold text-white">{{ $brand['display_name'] }}</span>
        <span class="inline-flex items-center gap-1.5"><x-heroicon-o-building-storefront class="size-4 text-gold-300"/>{{ $terminal ? $terminal['code'].' · '.$terminal['name'] : 'No terminal' }}</span>
        <span class="inline-flex items-center gap-1.5"><x-heroicon-o-user class="size-4 text-gold-300"/>{{ $cashierName }}</span>
        @if ($shift)
            <span class="inline-flex items-center gap-1.5"><x-heroicon-o-clock class="size-4 text-gold-300"/>Shift <span class="font-mono text-white">{{ $shift['number'] }}</span></span>
            <span class="inline-flex items-center gap-1.5"><x-heroicon-o-banknotes class="size-4 text-gold-300"/>Drawer <span class="font-mono text-white">{{ $money($shift['drawer']) }}</span></span>
        @endif
        <span class="ml-auto inline-flex items-center gap-1.5" :class="online ? 'text-emerald-300' : 'text-rose-300'">
            <span class="size-2 rounded-full" :class="online ? 'bg-emerald-400' : 'bg-rose-400'"></span><span x-text="online ? 'Online' : 'Offline'"></span>
        </span>
        <span class="font-mono text-white" x-text="now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })"></span>
        @if ($shift)
            <button type="button" class="rounded px-2 py-1 hover:bg-white/10" wire:click="$set('dialog', 'sales')" title="Sales on this shift (F6)">Sales</button>
            <button type="button" class="rounded px-2 py-1 hover:bg-white/10" wire:click="$set('dialog', 'held')" title="Held sales (F7)">Held</button>
            @if ($canCash)<button type="button" class="rounded px-2 py-1 hover:bg-white/10" wire:click="$set('dialog', 'cash')">Cash in/out</button>@endif
            <button type="button" class="rounded px-2 py-1 hover:bg-white/10" wire:click="$set('dialog', 'close')">Close shift</button>
        @endif
        <button type="button" class="rounded px-2 py-1 hover:bg-white/10" title="Full screen"
                @click="document.fullscreenElement ? document.exitFullscreen() : document.documentElement.requestFullscreen()"><x-heroicon-o-arrows-pointing-out class="size-4"/></button>
        <a href="{{ route('dashboard') }}" class="rounded px-2 py-1 hover:bg-white/10" title="Leave the till"><x-heroicon-o-arrow-right-start-on-rectangle class="size-4"/></a>
    </header>

    <div x-show="! online" x-cloak class="bg-rose-600 px-4 py-1.5 text-center text-sm font-medium text-white">
        Connection lost. The desktop till needs the server to sell; use the POS app on a tablet or phone to keep selling offline.
    </div>

    @if (! $shift)
        {{-- No open shift: open one --}}
        <main class="grid flex-1 place-items-center overflow-y-auto p-6">
            <div class="w-full max-w-md space-y-4">
                @if ($closedShift)
                    <div class="card p-5 text-sm">
                        <h2 class="mb-2 text-base font-semibold">Shift {{ $closedShift['number'] }} closed</h2>
                        <dl class="space-y-1">
                            <div class="flex justify-between"><dt>Expected in drawer</dt><dd class="font-mono">{{ $money($closedShift['expected_cash'] ?? null) }}</dd></div>
                            <div class="flex justify-between"><dt>Counted</dt><dd class="font-mono">{{ $money($closedShift['counted_cash'] ?? null) }}</dd></div>
                            <div class="flex justify-between font-semibold"><dt>Difference</dt><dd class="font-mono {{ bccomp((string) ($closedShift['variance'] ?? '0'), '0', 4) === 0 ? 'text-emerald-700' : 'text-rose-700' }}">{{ $money($closedShift['variance'] ?? null) }}</dd></div>
                        </dl>
                        <p class="mt-2 text-xs text-slate-500">A supervisor reviews the shift in Point of sale → Shifts.</p>
                    </div>
                @endif
                <form wire:submit="openShift" class="card space-y-4 p-6">
                    <div>
                        <h1 class="text-xl font-semibold text-navy-950">Open a shift</h1>
                        <p class="text-sm text-slate-500">Count the cash in the drawer before you start.</p>
                    </div>
                    @if ($terminals === [])
                        <p class="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">No active POS terminal is available to you. A manager registers terminals in Point of sale → Terminals.</p>
                    @else
                        <x-ui.field label="Terminal" for="shift.pos_terminal_id">
                            <select wire:model="terminalId" class="form-select" autofocus>
                                <option value="">Choose…</option>
                                @foreach ($terminals as $t)<option value="{{ $t['id'] }}">{{ $t['code'] }} · {{ $t['name'] }}</option>@endforeach
                            </select>
                        </x-ui.field>
                        <x-ui.field label="Opening cash" for="shift.opening_float"><input wire:model="openingFloat" inputmode="decimal" class="form-input text-lg"></x-ui.field>
                        <button class="btn-primary w-full py-3 text-base">Open shift</button>
                    @endif
                </form>
            </div>
        </main>
    @else
        <main class="grid min-h-0 flex-1 grid-cols-1 lg:grid-cols-[minmax(0,1fr)_26rem]">
            {{-- Products: the terminal's catalogue, searched in the browser --}}
            <section wire:ignore class="flex min-h-0 flex-col border-r border-slate-200"
                     x-data="{
                        items: [], loaded: false, error: null, q: '', asOf: null,
                        async load() {
                            this.error = null;
                            try {
                                const r = await fetch(@js(route('pos.catalogue', $shift['pos_terminal_id'])), { headers: { Accept: 'application/json' } });
                                const body = await r.json();
                                if (! r.ok) throw new Error(body.message || 'The catalogue could not be loaded.');
                                this.items = body.data.items; this.asOf = body.data.as_of; this.loaded = true;
                            } catch (e) { this.error = e.message }
                        },
                        price(v) { try { return new Intl.NumberFormat(undefined, { style: 'currency', currency: @js($currency ?: 'USD') }).format(v) } catch { return Number(v).toFixed(2) } },
                        level(i) { return i.package_levels.find(l => l.unit_price !== null) || i.package_levels[0] || null },
                        stock(i, l) { const base = i.batches.reduce((s, b) => s + parseFloat(b.free_base_units), 0); return l ? Math.floor(base / parseFloat(l.base_unit_quantity || 1)) : base },
                        get results() {
                            const q = this.q.trim().toLowerCase();
                            if (! q) return this.items.slice(0, 60);
                            const words = q.split(/\s+/);
                            return this.items.filter(i => {
                                const hay = (i.display_name + ' ' + (i.generic_name || '') + ' ' + (i.sku || '') + ' ' + i.barcodes.join(' ')).toLowerCase();
                                return words.every(w => hay.includes(w));
                            }).slice(0, 60);
                        },
                        exact(code) {
                            const c = code.trim(); if (! c) return null;
                            return this.items.find(i => i.sku === c || i.barcodes.includes(c) || i.batches.some(b => b.batch_number === c.toUpperCase())) || null;
                        },
                        pick(i) {
                            const l = this.level(i);
                            if (! l || l.unit_price === null) { $dispatch('notify', { type: 'error', message: i.display_name + ' has no price.' }); return; }
                            $wire.add(i.product_id, i.display_name, l.code, i.classification, i.package_levels);
                            this.q = ''; this.$refs.search.focus();
                        },
                        enter() {
                            const hit = this.exact(this.q) || (this.results.length === 1 ? this.results[0] : null);
                            if (hit) this.pick(hit); else if (this.q.trim()) $dispatch('notify', { type: 'warning', message: 'No single product matches “' + this.q + '”.' });
                        },
                     }"
                     x-init="load(); $nextTick(() => $refs.search.focus())"
                     @pos-focus-search.window="q = ''; $refs.search.focus()">
                <div class="flex items-center gap-2 border-b border-slate-200 bg-white p-3">
                    <x-heroicon-o-magnifying-glass class="size-5 shrink-0 text-slate-400"/>
                    <input x-ref="search" x-model="q" @keydown.enter.prevent="enter()" type="search" autocomplete="off"
                           placeholder="Scan a barcode or batch, or type a name or SKU (F2)" class="w-full border-0 bg-transparent text-lg focus:ring-0" aria-label="Scan or search">
                    <button type="button" class="btn-light btn-sm" @click="load()" title="Reload prices and stock">Refresh</button>
                </div>
                <div class="min-h-0 flex-1 overflow-y-auto p-3">
                    <p x-show="! loaded && ! error" class="p-6 text-center text-sm text-slate-500">Loading the catalogue…</p>
                    <p x-show="error" x-cloak class="rounded-lg bg-rose-50 p-3 text-sm text-rose-800" x-text="error"></p>
                    <div x-show="loaded" class="grid grid-cols-2 gap-2 xl:grid-cols-3 2xl:grid-cols-4">
                        <template x-for="i in results" :key="i.product_id">
                            <button type="button" @click="pick(i)" :disabled="stock(i, level(i)) <= 0"
                                    class="flex flex-col rounded-xl border border-slate-200 bg-white p-3 text-left shadow-sm transition hover:border-[var(--brand-primary)] hover:shadow disabled:cursor-not-allowed disabled:opacity-50">
                                <span class="line-clamp-2 font-medium text-navy-950" x-text="i.display_name"></span>
                                <span class="text-xs text-slate-500" x-text="(i.sku || '') + (i.generic_name ? ' · ' + i.generic_name : '')"></span>
                                <span class="mt-2 flex items-end justify-between gap-2">
                                    <span class="font-mono text-base font-semibold" x-text="level(i) && level(i).unit_price !== null ? price(level(i).unit_price) : 'No price'"></span>
                                    <span class="text-right text-[11px] leading-tight text-slate-500">
                                        <span x-text="level(i)?.name || ''"></span><br>
                                        <span :class="stock(i, level(i)) > 0 ? '' : 'text-rose-600'" x-text="stock(i, level(i)) > 0 ? stock(i, level(i)) + ' in stock' : 'Out of stock'"></span>
                                    </span>
                                </span>
                                <span x-show="i.classification === 'prescription_only'" class="mt-1 w-fit rounded bg-amber-100 px-1.5 text-[11px] font-semibold text-amber-800">Rx · pharmacist</span>
                            </button>
                        </template>
                    </div>
                    <p x-show="loaded && results.length === 0" x-cloak class="p-6 text-center text-sm text-slate-500">No product matches.</p>
                    <p x-show="loaded" class="mt-3 text-[11px] text-slate-400" x-text="'Prices and stock as of ' + (asOf ? new Date(asOf).toLocaleTimeString() : '') + '; the server checks them again for every sale.'"></p>
                </div>
            </section>

            {{-- Cart and payment --}}
            <aside class="flex min-h-0 flex-col bg-white">
                @if ($completed)
                    <div class="flex flex-1 flex-col items-center justify-center gap-4 p-6 text-center">
                        <x-heroicon-o-check-circle class="size-14 text-emerald-500"/>
                        <p class="text-lg font-semibold">Sale {{ $completed['number'] }} completed</p>
                        <p class="text-sm text-slate-500">Total {{ $money($completed['total']) }}</p>
                        <div class="rounded-2xl bg-emerald-50 px-8 py-4">
                            <p class="text-xs font-semibold uppercase tracking-wider text-emerald-700">Change due</p>
                            <p class="font-mono text-4xl font-bold text-emerald-800">{{ $money($completed['change_due']) }}</p>
                        </div>
                        <div class="flex gap-2">
                            <a href="{{ route('pos.receipt', $completed['id']) }}" target="pos-receipt" class="btn-light" @click.prevent="$refs.printer.src = $el.href">Print receipt</a>
                            <button type="button" id="pos-primary" class="btn-primary" wire:click="newSale" x-init="$el.focus()">New sale (F2)</button>
                        </div>
                        <label class="form-check text-xs text-slate-500"><input type="checkbox" :checked="autoPrint" @change="setAutoPrint($event.target.checked)"> Print receipts automatically</label>
                    </div>
                @else
                    <div class="flex items-center gap-2 border-b border-slate-200 px-4 py-3">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-semibold">{{ $sale ? 'Sale '.$sale['number'] : 'New sale' }} @if ($status && $status !== 'open')<x-ui.badge :status="$status">{{ str_replace('_', ' ', $status) }}</x-ui.badge>@endif</p>
                            <button type="button" class="link text-xs" wire:click="$set('dialog', 'customer')">{{ $customer['name'] ?? 'Walk-in customer' }} · change (F4)</button>
                        </div>
                    </div>

                    <ol class="min-h-0 flex-1 divide-y divide-slate-100 overflow-y-auto">
                        @forelse ($lines as $i => $line)
                            @php $priced = $saleLines[$i] ?? null; @endphp
                            <li class="px-4 py-2.5" wire:key="line-{{ $i }}-{{ $line['product_id'] }}">
                                <div class="flex items-start justify-between gap-2">
                                    <p class="text-sm font-medium leading-tight">{{ $line['name'] }}
                                        @if (($line['classification'] ?? null) === 'prescription_only')
                                            <button type="button" class="ml-1 rounded px-1 text-[11px] font-semibold {{ $line['prescription'] ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800' }}" wire:click="startPrescription({{ $i }})">Rx {{ $line['prescription'] ? '✓' : 'needed' }}</button>
                                        @endif
                                    </p>
                                    <span class="font-mono text-sm font-semibold">{{ $priced ? $money($priced['line_total']) : '…' }}</span>
                                </div>
                                <div class="mt-1.5 flex items-center gap-2 text-sm">
                                    <div class="flex items-center rounded-lg border border-slate-200">
                                        <button type="button" class="px-2 py-1 hover:bg-slate-100" wire:click="step({{ $i }}, -1)" @disabled($paying) aria-label="One less">−</button>
                                        <input type="text" inputmode="decimal" value="{{ rtrim(rtrim($line['quantity'], '0'), '.') }}" wire:change="setQuantity({{ $i }}, $event.target.value)" @disabled($paying)
                                               class="w-12 border-0 p-1 text-center focus:ring-0" aria-label="Quantity">
                                        <button type="button" class="px-2 py-1 hover:bg-slate-100" wire:click="step({{ $i }}, 1)" @disabled($paying) aria-label="One more">+</button>
                                    </div>
                                    @if (count($line['levels'] ?? []) > 1 && ! $paying)
                                        <select wire:change="setLevel({{ $i }}, $event.target.value)" class="rounded border-slate-200 py-0.5 pl-1.5 pr-6 text-xs" aria-label="Pack size">
                                            @foreach ($line['levels'] as $code => $label)<option value="{{ $code }}" @selected($code === $line['package_level_code'])>{{ $label }}</option>@endforeach
                                        </select>
                                        <span class="text-xs text-slate-500">× {{ $priced ? $money($priced['unit_price']) : '' }}</span>
                                    @else
                                        <span class="text-xs text-slate-500">{{ $line['levels'][$line['package_level_code']] ?? $line['package_level_code'] }} × {{ $priced ? $money($priced['unit_price']) : '' }}</span>
                                    @endif
                                    @if (! $paying)
                                        <input type="text" inputmode="decimal" value="{{ $line['discount_percent'] !== '0' ? $line['discount_percent'] : '' }}" placeholder="disc %" wire:change="setDiscount({{ $i }}, $event.target.value)"
                                               class="ml-auto w-16 rounded border-slate-200 px-1.5 py-0.5 text-right text-xs" aria-label="Discount percent">
                                        <button type="button" class="text-slate-400 hover:text-rose-600" wire:click="removeLine({{ $i }})" aria-label="Remove {{ $line['name'] }}"><x-heroicon-o-x-mark class="size-4"/></button>
                                    @endif
                                </div>
                            </li>
                        @empty
                            <li class="p-8 text-center text-sm text-slate-500">Scan or pick products to start a sale.</li>
                        @endforelse
                    </ol>

                    <div class="space-y-1 border-t border-slate-200 px-4 py-3 text-sm">
                        <div class="flex justify-between"><span>Subtotal</span><span class="font-mono">{{ $money($sale['subtotal'] ?? 0) }}</span></div>
                        @if (bccomp((string) ($sale['discount_total'] ?? '0'), '0', 4) > 0)<div class="flex justify-between text-emerald-700"><span>Discount</span><span class="font-mono">−{{ $money($sale['discount_total']) }}</span></div>@endif
                        <div class="flex justify-between"><span>Tax</span><span class="font-mono">{{ $money($sale['tax_total'] ?? 0) }}</span></div>
                        <div class="flex justify-between text-2xl font-bold text-navy-950"><span>Total</span><span class="font-mono">{{ $money($sale['total'] ?? 0) }}</span></div>
                    </div>

                    @if ($paying)
                        <div class="space-y-3 border-t border-slate-200 bg-slate-50 px-4 py-3" @if ($providerInstructions) wire:poll.3s="refreshSale" @endif>
                            <div class="flex items-center justify-between text-sm"><span class="font-semibold">To pay</span><span class="font-mono text-xl font-bold">{{ $money($outstanding) }}</span></div>
                            @foreach ($sale['payments'] ?? [] as $p)
                                @if (in_array($p['status'], ['confirmed', 'pending'], true))
                                    <p class="flex justify-between text-xs text-slate-600"><span>{{ str_replace('_', ' ', $p['method']) }} @if ($p['status'] === 'pending')<span class="text-amber-700">(awaiting confirmation)</span>@endif</span><span class="font-mono">{{ $money($p['amount']) }}</span></p>
                                @endif
                            @endforeach

                            @if ($providerInstructions)
                                <div class="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">
                                    <p class="font-semibold">Waiting for the payment provider…</p>
                                    @foreach ($providerInstructions as $k => $v)@if (is_scalar($v))<p class="text-xs">{{ str_replace('_', ' ', ucfirst($k)) }}: <span class="font-mono">{{ $v }}</span></p>@endif @endforeach
                                </div>
                            @elseif (bccomp($outstanding, '0', 4) > 0)
                                <div class="flex flex-wrap gap-1">
                                    @foreach ($paymentMethods as $m)
                                        <button type="button" wire:click="$set('payMethod', '{{ $m['channel'] }}')" class="rounded-lg border px-2.5 py-1 text-xs font-medium {{ $payMethod === $m['channel'] ? 'border-[var(--brand-primary)] bg-[var(--brand-primary)] text-white' : 'border-slate-200 bg-white' }}">{{ $m['channel'] === 'manual_terminal' ? 'Card / transfer terminal' : $m['name'] }}</button>
                                    @endforeach
                                </div>
                                @if ($payMethod === 'cash')
                                    <form wire:submit="pay" class="flex gap-2">
                                        <input wire:model="tendered" inputmode="decimal" placeholder="Cash received" class="form-input text-lg" autofocus x-init="$el.focus()">
                                        <button id="pos-primary" class="btn-primary">Pay (F9)</button>
                                    </form>
                                    <div class="flex flex-wrap gap-1">
                                        <button type="button" class="btn-light btn-sm" wire:click="pay('{{ $outstanding }}')">Exact</button>
                                        @foreach ($quick as $q)<button type="button" class="btn-light btn-sm" wire:click="pay('{{ $q }}')">{{ $money($q) }}</button>@endforeach
                                    </div>
                                @elseif ($payMethod === 'manual_terminal')
                                    <form wire:submit="pay" class="space-y-2">
                                        <div class="flex gap-3 text-xs">@foreach (['card' => 'Card', 'bank_transfer' => 'Transfer', 'mobile_money' => 'Mobile money'] as $v => $l)<label class="form-check"><input type="radio" wire:model="terminalMethod" value="{{ $v }}"> {{ $l }}</label>@endforeach</div>
                                        <div class="flex gap-2">
                                            <input wire:model="approvalCode" placeholder="Approval code on the terminal slip" class="form-input" x-init="$el.focus()">
                                            <button id="pos-primary" class="btn-primary">Record</button>
                                        </div>
                                        <p class="text-[11px] text-slate-500">Recorded as pending: a supervisor confirms it against the terminal's settlement.</p>
                                    </form>
                                @else
                                    <button type="button" id="pos-primary" class="btn-primary w-full" wire:click="pay">Request {{ $money($outstanding) }} via {{ $method['name'] ?? $payMethod }}</button>
                                @endif
                                @error('payment.amount')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
                            @else
                                <p class="text-sm text-amber-800">Fully covered; waiting for pending payments to be confirmed.</p>
                            @endif
                            <button type="button" class="text-xs text-rose-700 hover:underline" wire:click="$set('dialog', 'cancel')">Cancel sale</button>
                        </div>
                    @else
                        <div class="grid grid-cols-[auto_auto_1fr] gap-2 border-t border-slate-200 px-4 py-3">
                            <button type="button" class="btn-light" wire:click="hold" @disabled(! $sale) title="Hold (F8)">Hold</button>
                            <button type="button" class="btn-light text-rose-700" wire:click="$set('dialog', 'cancel')" @disabled(! $sale)>Cancel</button>
                            <button type="button" id="pos-primary" class="btn-primary py-3 text-base" wire:click="checkout" @disabled($lines === [])>Pay {{ $money($sale['total'] ?? 0) }} (F9)</button>
                        </div>
                    @endif
                @endif
            </aside>
        </main>
    @endif

    <iframe x-ref="printer" name="pos-receipt" class="hidden" title="Receipt"></iframe>

    {{-- Dialogs --}}
    <x-ui.modal wire:model="dialog" :title="['customer' => 'Customer', 'prescription' => 'Prescription', 'cash' => 'Cash in or out', 'close' => 'Close shift', 'sales' => 'Sales on this shift', 'held' => 'Held sales', 'cancel' => 'Cancel sale'][$dialog] ?? ''" :size="in_array($dialog, ['sales'], true) ? 'lg' : 'md'">
        @if ($dialog === 'customer')
            <div class="space-y-3">
                <input wire:model.live.debounce.300ms="customerSearch" placeholder="Search customers by name or code" class="form-input" x-init="$el.focus()">
                <ul class="max-h-80 divide-y divide-slate-100 overflow-y-auto rounded-lg border border-slate-200">
                    <li><button type="button" class="w-full px-3 py-2 text-left text-sm hover:bg-slate-50" wire:click="chooseCustomer(null, null)">Walk-in customer</button></li>
                    @foreach ($customers as $c)
                        <li wire:key="cu-{{ $c['id'] }}"><button type="button" class="w-full px-3 py-2 text-left text-sm hover:bg-slate-50" wire:click="chooseCustomer('{{ $c['id'] }}', @js($c['name']))"><span class="font-medium">{{ $c['name'] }}</span> <span class="text-xs text-slate-500">{{ $c['code'] }}</span></button></li>
                    @endforeach
                </ul>
                <p class="text-xs text-slate-500">Customer prices and price lists apply when the sale starts.</p>
            </div>
        @elseif ($dialog === 'prescription')
            <form wire:submit="savePrescription" id="rx-form" class="space-y-3">
                <p class="text-sm">{{ $lines[$prescriptionLine]['name'] ?? '' }} is prescription-only. @unless ($canDispense)<span class="text-rose-700">It must be dispensed by a pharmacist.</span>@endunless</p>
                @error('prescription')<p class="rounded bg-amber-50 p-2 text-sm text-amber-900">{{ $message }}</p>@enderror
                <x-ui.field label="Prescription reference" for="prescription.reference"><input wire:model="prescription.reference" class="form-input" x-init="$el.focus()"></x-ui.field>
                <x-ui.field label="Prescriber" for="prescription.prescriber_name"><input wire:model="prescription.prescriber_name" class="form-input"></x-ui.field>
                <x-ui.field label="Prescribed on" for="prescription.prescribed_on"><input type="date" wire:model="prescription.prescribed_on" class="form-input"></x-ui.field>
            </form>
        @elseif ($dialog === 'cash')
            <form wire:submit="cashMovement" id="cash-form" class="space-y-3">
                <div class="flex gap-4 text-sm"><label class="form-check"><input type="radio" wire:model="cash.direction" value="in"> Cash in</label><label class="form-check"><input type="radio" wire:model="cash.direction" value="out"> Cash out</label></div>
                <x-ui.field label="Amount" for="cash.amount"><input wire:model="cash.amount" inputmode="decimal" class="form-input"></x-ui.field>
                <x-ui.field label="Reason" for="cash.reason"><input wire:model="cash.reason" class="form-input" placeholder="For example: change from the safe, banking"></x-ui.field>
            </form>
        @elseif ($dialog === 'close')
            <form wire:submit="closeShift" id="close-form" class="space-y-3">
                <p class="text-sm">Count the cash in the drawer. The system expects <span class="font-mono font-semibold">{{ $money($shift['drawer'] ?? 0) }}</span>; any difference is recorded for a supervisor.</p>
                <x-ui.field label="Counted cash" for="close.counted_cash"><input wire:model="countedCash" inputmode="decimal" class="form-input text-lg" x-init="$el.focus()"></x-ui.field>
                <x-ui.field label="Notes" for="close.notes"><textarea wire:model="closeNotes" rows="2" class="form-input"></textarea></x-ui.field>
            </form>
        @elseif ($dialog === 'held')
            <ul class="divide-y divide-slate-100">
                @forelse ($held as $h)
                    <li class="flex items-center justify-between py-2 text-sm" wire:key="h-{{ $h['id'] }}"><span><span class="font-mono font-semibold">{{ $h['number'] }}</span> · {{ fmt()->dateTime($h['created_at']) }}</span><span class="flex items-center gap-3"><span class="font-mono">{{ $money($h['total']) }}</span><button type="button" class="btn-light btn-sm" wire:click="resume('{{ $h['id'] }}')">Resume</button></span></li>
                @empty
                    <li class="py-6 text-center text-sm text-slate-500">No held sales on this shift.</li>
                @endforelse
            </ul>
        @elseif ($dialog === 'sales')
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Sale</th><th>Time</th><th>Status</th><th class="num">Total</th><th></th></tr></thead>
                    <tbody>
                        @forelse ($recent as $r)
                            <tr wire:key="s-{{ $r['id'] }}"><td class="font-mono text-xs font-semibold">{{ $r['number'] }}</td><td>{{ $r['completed_at'] ? fmt()->dateTime($r['completed_at']) : fmt()->dateTime($r['created_at']) }}</td>
                                <td><x-ui.badge :status="$r['status']"/></td><td class="num">{{ $money($r['total']) }}</td>
                                <td class="text-right">@if ($r['status'] === 'completed')<a class="link text-xs" href="{{ route('pos.receipt', $r['id']) }}" @click.prevent="$refs.printer.src = $el.href">Reprint</a> · <a class="link text-xs" href="{{ route('pos.refunds', ['sale' => $r['number']]) }}" target="_blank">Refund</a>@endif</td></tr>
                        @empty
                            <x-ui.empty colspan="5" title="No sales yet on this shift"/>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @elseif ($dialog === 'cancel')
            <form wire:submit="cancelSale" id="cancel-form" class="space-y-3">
                <p class="text-sm">Reserved stock is released{{ $paying ? ', and cash already taken is returned from the drawer' : '' }}. @if ($paying && ! in_array('pos.sale.cancel', $permissions, true))<span class="text-amber-800">Once payment has been taken, a supervisor must cancel.</span>@endif</p>
                <x-ui.field label="Reason" for="cancel.reason"><input wire:model="cancelReason" class="form-input" placeholder="For example: customer changed their mind" x-init="$el.focus()"></x-ui.field>
            </form>
        @endif
        <x-slot:footer>
            <button type="button" class="btn-light" @click="open = null">Close (Esc)</button>
            @if ($dialog === 'prescription')<button type="submit" form="rx-form" class="btn-primary">Save prescription</button>@endif
            @if ($dialog === 'cash')<button type="submit" form="cash-form" class="btn-primary">Record</button>@endif
            @if ($dialog === 'close')<button type="submit" form="close-form" class="btn-primary">Close shift</button>@endif
            @if ($dialog === 'cancel')<button type="submit" form="cancel-form" class="btn-danger">Cancel sale</button>@endif
        </x-slot:footer>
    </x-ui.modal>
</div>

<div>
    <x-ui.toolbar>
        <x-ui.field label="Payment" for="paymentStatus">
            <select id="paymentStatus" wire:model.live="paymentStatus" class="form-select w-40"><option value="">All</option><option value="unpaid">Unpaid</option><option value="partial">Partly paid</option><option value="paid">Paid</option></select>
        </x-ui.field>
        <x-ui.field label="Customer" for="customer">
            <select id="customer" wire:model.live="customer" class="form-select w-56"><option value="">All customers</option>@foreach ($customers as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
        </x-ui.field>
        <x-slot:actions>
            @if ($can['pay'])<a href="{{ route('sales.payments') }}" class="btn-light"><x-heroicon-o-credit-card class="size-4"/>Receive a payment</a>@endif
        </x-slot:actions>
    </x-ui.toolbar>

    <div class="card overflow-hidden">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Number</th><th>Customer</th><th>Date</th><th>Due</th><th class="num">Total</th><th class="num">Paid</th><th class="num">Credited</th><th>Payment</th><th>Status</th></tr></thead>
                <tbody>
                    @forelse ($rows as $i)
                        <tr wire:key="i-{{ $i['id'] }}" class="cursor-pointer" wire:click="$set('openId', '{{ $i['id'] }}')">
                            <td class="font-mono text-xs font-semibold">{{ $i['number'] }}</td>
                            <td class="font-medium text-navy-900">{{ $customers[$i['customer_id']] ?? '' }}</td>
                            <td>{{ fmt()->date($i['invoice_date']) }}</td>
                            <td>{{ $i['due_date'] ? fmt()->date($i['due_date']) : '—' }}</td>
                            <td class="num font-semibold">{{ fmt()->money($i['total'], $i['currency_code']) }}</td>
                            <td class="num">{{ fmt()->number($i['amount_paid'], 2) }}</td>
                            <td class="num">{{ fmt()->number($i['amount_credited'], 2) }}</td>
                            <td><x-ui.badge :status="$i['payment_status'] === 'partial' ? 'partially_paid' : $i['payment_status']">{{ $i['payment_status'] }}</x-ui.badge></td>
                            <td><x-ui.badge :status="$i['status']"/></td>
                        </tr>
                    @empty
                        <x-ui.empty colspan="9" icon="document-text" title="No invoices"/>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-ui.pager :paginator="$meta"/>
    </div>

    <x-ui.modal wire:model="openId" :title="'Invoice '.($detail['number'] ?? '')" size="xl">
        @if ($detail)
            <div class="mb-4 grid gap-4 sm:grid-cols-4">
                <x-ui.stat label="Total" :value="fmt()->money($detail['total'], $detail['currency_code'])"/>
                <x-ui.stat label="Paid" :value="fmt()->money($detail['amount_paid'], $detail['currency_code'])" tone="green"/>
                <x-ui.stat label="Credited" :value="fmt()->money($detail['amount_credited'], $detail['currency_code'])" tone="gold"/>
                <x-ui.stat label="Open balance" :value="fmt()->money($detail['open_balance'], $detail['currency_code'])" :tone="(float) $detail['open_balance'] > 0 ? 'red' : 'green'"/>
            </div>
            <p class="mb-3 text-sm text-slate-600">{{ $customers[$detail['customer_id']] ?? '' }} · issued {{ fmt()->date($detail['invoice_date']) }} · due {{ $detail['due_date'] ? fmt()->date($detail['due_date']) : '—' }}
                @if ($detail['sales_order_id'])· <a href="{{ route('sales.orders', ['open' => $detail['sales_order_id']]) }}" class="link">sales order</a>@endif</p>
            @include('livewire.partials.sales-lines', ['document' => $detail])

            @if (! empty($detail['credit_notes']))
                <p class="mb-1 mt-4 text-xs font-semibold uppercase text-slate-400">Credit notes</p>
                @foreach ($detail['credit_notes'] as $cn)
                    <p class="text-sm"><span class="font-mono text-xs">{{ $cn['number'] }}</span> · {{ str_replace('_', ' ', $cn['reason_code']) }} · {{ fmt()->money($cn['total'], $cn['currency_code']) }} · {{ fmt()->date($cn['credit_date']) }}</p>
                @endforeach
            @endif

            @if ($credit)
                <form wire:submit="saveCredit" class="mt-5 space-y-3 rounded-lg border border-slate-200 bg-slate-50 p-4">
                    <p class="text-sm font-semibold text-navy-950">Issue a credit note</p>
                    <div class="grid gap-3 sm:grid-cols-3">
                        <x-ui.field label="Reason" for="credit.reason_code">
                            <select id="credit.reason_code" wire:model.live="credit.reason_code" class="form-select py-2">
                                @foreach (['return' => 'Goods returned', 'price_adjustment' => 'Price adjustment', 'damaged_in_transit' => 'Damaged in transit', 'other' => 'Other'] as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
                            </select>
                        </x-ui.field>
                        @if ($credit['reason_code'] === 'return')
                            <x-ui.field label="Return to warehouse" for="credit.return_warehouse_id">
                                <select id="credit.return_warehouse_id" wire:model="credit.return_warehouse_id" class="form-select py-2">@foreach ($warehouses as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
                            </x-ui.field>
                        @endif
                        <x-ui.field label="Notes" for="credit.notes"><input id="credit.notes" wire:model="credit.notes" class="form-input py-2"></x-ui.field>
                    </div>
                    @error('credit.lines') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
                    @foreach ($detail['lines'] as $l)
                        <div class="flex flex-wrap items-center justify-between gap-3 text-sm">
                            <span>{{ $names[$l['product_id']] ?? '' }} · {{ fmt()->number($l['quantity'], 2) }} {{ $l['package_level_code'] }} invoiced</span>
                            <span class="flex gap-2">
                                @if ($credit['reason_code'] === 'return')
                                    <select wire:model="credit.lines.{{ $l['id'] }}.restock_state" class="form-select w-36 py-1.5" aria-label="Returned stock goes to"><option value="quarantined">Quarantine</option><option value="available">Available</option><option value="damaged">Damaged</option></select>
                                @endif
                                <input wire:model="credit.lines.{{ $l['id'] }}.quantity" inputmode="decimal" class="form-input w-24 py-1.5 text-right" placeholder="Qty" aria-label="Credit quantity">
                            </span>
                        </div>
                    @endforeach
                    <div class="flex justify-end gap-2"><button type="button" class="btn-light btn-sm" wire:click="$set('credit', null)">Back</button><button class="btn-primary btn-sm">Issue credit note</button></div>
                </form>
            @endif
        @endif
        <x-slot:footer>
            @if ($detail && ! $credit && $detail['status'] === 'issued')
                @if ($can['credit'])<button type="button" class="btn-light" wire:click="startCredit">Credit note</button>@endif
                @if ($can['pay'] && (float) $detail['open_balance'] > 0)<a href="{{ route('sales.payments', ['customer' => $detail['customer_id']]) }}" class="btn-primary"><x-heroicon-o-credit-card class="size-4"/>Receive payment</a>@endif
            @endif
        </x-slot:footer>
    </x-ui.modal>
</div>

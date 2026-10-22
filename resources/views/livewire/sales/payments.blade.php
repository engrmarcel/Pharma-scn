<div class="space-y-6">
    <x-ui.card title="Receive a payment" icon="credit-card">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-6">
            <x-ui.field label="Customer" for="payment.customer_id" class="lg:col-span-2">
                <select id="payment.customer_id" wire:model.live="customer" class="form-select"><option value="">Choose…</option>@foreach ($customers as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
            </x-ui.field>
            <x-ui.field :label="'Amount ('.$currency.')'" for="payment.amount"><input id="payment.amount" wire:model="form.amount" inputmode="decimal" class="form-input"></x-ui.field>
            <x-ui.field label="Method" for="payment.method">
                <select id="payment.method" wire:model="form.method" class="form-select">@foreach ($methods as $m)<option value="{{ $m }}">{{ ucfirst(str_replace('_', ' ', $m)) }}</option>@endforeach</select>
            </x-ui.field>
            <x-ui.field label="Date" for="payment.payment_date"><input id="payment.payment_date" type="date" wire:model="form.payment_date" class="form-input"></x-ui.field>
            <x-ui.field label="Reference" for="payment.reference"><input id="payment.reference" wire:model="form.reference" class="form-input" placeholder="Bank ref."></x-ui.field>
        </div>

        @if ($customer)
            <div class="mt-5">
                <div class="mb-2 flex items-center justify-between"><p class="text-sm font-semibold text-navy-950">Allocate to open invoices</p><button type="button" class="link text-xs" wire:click="autoAllocate">Oldest first</button></div>
                @error('payment.allocations') <p class="mb-2 text-xs text-rose-600">{{ $message }}</p> @enderror
                <div class="table-wrap rounded-lg border border-slate-200">
                    <table class="data-table">
                        <thead><tr><th>Invoice</th><th>Due</th><th class="num">Open</th><th class="num">Allocate</th></tr></thead>
                        <tbody>
                            @forelse ($invoices as $inv)
                                <tr wire:key="pi-{{ $inv['id'] }}"><td class="font-mono text-xs">{{ $inv['number'] }}</td><td>{{ $inv['due_date'] ? fmt()->date($inv['due_date']) : '—' }}</td>
                                    <td class="num">{{ fmt()->money($inv['open'], $inv['currency_code']) }}</td>
                                    <td class="num"><input wire:model="allocations.{{ $inv['id'] }}" inputmode="decimal" class="form-input ml-auto w-32 py-1.5 text-right" aria-label="Allocate"></td></tr>
                            @empty
                                <x-ui.empty colspan="4" icon="check-circle" title="No open invoices">The payment will be held on account.</x-ui.empty>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <p class="mt-2 text-xs text-slate-500">Anything not allocated stays on the customer's account as an unallocated payment.</p>
            </div>
        @endif
        @if ($canRecord)<div class="mt-4 flex justify-end"><button type="button" class="btn-primary" wire:click="receive" wire:loading.attr="disabled" @disabled(! $customer)><x-heroicon-o-check class="size-4"/>Record payment</button></div>@endif
    </x-ui.card>

    @if ($payments !== null)
        <div class="card overflow-hidden">
            <div class="card-header"><h2 class="card-title"><x-heroicon-o-list-bullet class="size-4 text-gold-500"/>{{ $customer ? 'Payments from this customer' : 'Recent customer payments' }}</h2></div>
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Number</th><th>Customer</th><th>Date</th><th>Method</th><th>Reference</th><th class="num">Amount</th><th class="num">Allocated</th><th>Status</th></tr></thead>
                    <tbody>
                        @forelse ($payments as $p)
                            <tr wire:key="p-{{ $p['id'] }}"><td class="font-mono text-xs">{{ $p['number'] }}</td><td>{{ $customers[$p['party_id']] ?? '' }}</td><td>{{ fmt()->date($p['payment_date']) }}</td>
                                <td>{{ str_replace('_', ' ', $p['method']) }}</td><td>{{ $p['reference'] ?? '—' }}</td><td class="num font-semibold">{{ fmt()->money($p['amount'], $p['currency_code']) }}</td>
                                <td class="num">{{ fmt()->number($p['allocated_amount'], 2) }}</td><td><x-ui.badge :status="$p['status']"/></td></tr>
                        @empty
                            <x-ui.empty colspan="8" icon="banknotes" title="No payments yet"/>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <x-ui.pager :paginator="$meta"/>
        </div>
    @endif
</div>

<div>
    <x-ui.toolbar>
        <x-ui.field label="Party" for="party">
            <select id="party" wire:model.live="party" class="form-select w-44"><option value="">All payments</option><option value="supplier">To suppliers</option><option value="customer">From customers</option></select>
        </x-ui.field>
        <x-slot:actions>
            @can('finance.payments.record')<a href="{{ route('sales.payments') }}" class="btn-light">Receive from a customer</a>@endcan
            @if ($canRecord)<button type="button" class="btn-primary" wire:click="startPay"><x-heroicon-o-banknotes class="size-4"/>Pay a supplier</button>@endif
        </x-slot:actions>
    </x-ui.toolbar>

    <div class="card overflow-hidden">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Number</th><th>Direction</th><th>Party</th><th>Date</th><th>Method</th><th>Reference</th><th class="num">Amount</th><th class="num">Allocated</th><th></th></tr></thead>
                <tbody>
                    @forelse ($rows as $p)
                        <tr wire:key="p-{{ $p['id'] }}">
                            <td class="font-mono text-xs font-semibold">{{ $p['number'] }}</td>
                            <td><x-ui.badge :tone="$p['direction'] === 'incoming' ? 'green' : 'blue'">{{ $p['direction'] === 'incoming' ? 'received' : 'paid' }}</x-ui.badge></td>
                            <td class="font-medium text-navy-900">{{ $p['party_type'] === 'supplier' ? ($suppliers[$p['party_id']] ?? '') : ($customers[$p['party_id']] ?? '') }}</td>
                            <td>{{ fmt()->date($p['payment_date']) }}</td>
                            <td>{{ str_replace('_', ' ', $p['method']) }}</td>
                            <td>{{ $p['reference'] ?? '—' }}</td>
                            <td class="num font-semibold">{{ fmt()->money($p['amount'], $p['currency_code']) }}</td>
                            <td class="num">{{ fmt()->number($p['allocated_amount'], 2) }}</td>
                            <td class="text-right">
                                @if ($canRecord && $p['party_type'] === 'supplier' && (float) $p['allocated_amount'] < (float) $p['amount'])
                                    <button type="button" class="link text-xs" wire:click="startAllocate('{{ $p['id'] }}', '{{ $p['party_id'] }}')">Allocate</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <x-ui.empty colspan="9" icon="banknotes" title="No payments"/>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-ui.pager :paginator="$meta"/>
    </div>

    <x-ui.modal wire:model="pay" title="Pay a supplier" size="lg">
        @if ($pay)
            <form wire:submit="savePay" id="pay-form" class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-3">
                    <x-ui.field label="Supplier" for="pay.supplier_id">
                        <select id="pay.supplier_id" wire:model.live="pay.supplier_id" class="form-select"><option value="">Choose…</option>@foreach ($suppliers as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
                    </x-ui.field>
                    <x-ui.field :label="'Amount ('.$currency.')'" for="pay.amount"><input id="pay.amount" wire:model="pay.amount" inputmode="decimal" class="form-input"></x-ui.field>
                    <x-ui.field label="Date" for="pay.payment_date"><input id="pay.payment_date" type="date" wire:model="pay.payment_date" class="form-input"></x-ui.field>
                    <x-ui.field label="Method" for="pay.method"><select id="pay.method" wire:model="pay.method" class="form-select">@foreach ($methods as $m)<option value="{{ $m }}">{{ ucfirst(str_replace('_', ' ', $m)) }}</option>@endforeach</select></x-ui.field>
                    <x-ui.field label="Paid from" for="pay.account_id"><select id="pay.account_id" wire:model="pay.account_id" class="form-select"><option value="">Default bank account</option>@foreach ($cashAccounts as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach</select></x-ui.field>
                    <x-ui.field label="Reference" for="pay.reference"><input id="pay.reference" wire:model="pay.reference" class="form-input"></x-ui.field>
                </div>
                @include('livewire.finance.partials.allocations', ['model' => 'pay.allocations', 'error' => 'pay.allocations'])
            </form>
        @endif
        <x-slot:footer>
            <button type="button" class="btn-light" @click="open = null">Cancel</button>
            <button type="submit" form="pay-form" class="btn-primary" wire:loading.attr="disabled">Record payment</button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal wire:model="allocating" title="Allocate a payment" size="md">
        @if ($allocating)
            <form wire:submit="saveAllocate" id="alloc-form">@include('livewire.finance.partials.allocations', ['model' => 'allocating.allocations', 'error' => 'allocate.allocations'])</form>
        @endif
        <x-slot:footer>
            <button type="button" class="btn-light" @click="open = null">Cancel</button>
            <button type="submit" form="alloc-form" class="btn-primary">Allocate</button>
        </x-slot:footer>
    </x-ui.modal>
</div>

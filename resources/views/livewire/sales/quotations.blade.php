<div>
    <x-ui.toolbar>
        <p class="text-sm text-slate-600">Quotations hold prices for the customer until they expire; converting one creates a draft sales order.</p>
        <x-slot:actions>
            @if ($can['quote'])<button type="button" class="btn-primary" wire:click="create"><x-heroicon-o-plus class="size-4"/>New quotation</button>@endif
        </x-slot:actions>
    </x-ui.toolbar>

    <div class="card overflow-hidden">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Number</th><th>Customer</th><th>Created</th><th>Valid until</th><th class="num">Total</th><th>Status</th></tr></thead>
                <tbody>
                    @forelse ($rows as $q)
                        <tr wire:key="q-{{ $q['id'] }}" class="cursor-pointer" wire:click="$set('openId', '{{ $q['id'] }}')">
                            <td class="font-mono text-xs font-semibold">{{ $q['number'] }}</td>
                            <td class="font-medium text-navy-900">{{ $customers[$q['customer_id']] ?? '' }}</td>
                            <td>{{ fmt()->date($q['created_at']) }}</td>
                            <td>{{ $q['valid_until'] ? fmt()->date($q['valid_until']) : '—' }}</td>
                            <td class="num font-semibold">{{ fmt()->money($q['total'], $q['currency_code']) }}</td>
                            <td><x-ui.badge :status="$q['status']"/></td>
                        </tr>
                    @empty
                        <x-ui.empty colspan="6" icon="document-duplicate" title="No quotations"/>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-ui.pager :paginator="$meta"/>
    </div>

    <x-ui.modal wire:model="doc" title="New quotation" size="xl">
        @if ($doc)
            <form wire:submit="save" id="q-form" class="space-y-5">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <x-ui.field label="Customer" for="doc.customer_id">
                        <select id="doc.customer_id" wire:model="doc.customer_id" class="form-select"><option value="">Choose…</option>@foreach ($customers as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
                    </x-ui.field>
                    <x-ui.field label="Supply from" for="doc.warehouse_id">
                        <select id="doc.warehouse_id" wire:model="doc.warehouse_id" class="form-select"><option value="">Choose…</option>@foreach ($warehouses as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
                    </x-ui.field>
                    <x-ui.field label="Valid until" for="doc.valid_until"><input id="doc.valid_until" type="date" wire:model="doc.valid_until" class="form-input"></x-ui.field>
                    <x-ui.field label="Notes" for="doc.notes"><input id="doc.notes" wire:model="doc.notes" class="form-input"></x-ui.field>
                </div>
                @include('livewire.partials.document-lines', ['form' => 'doc', 'rateField' => 'discount_percent', 'rateLabel' => 'Disc %'])
            </form>
        @endif
        <x-slot:footer>
            <button type="button" class="btn-light" @click="open = null">Cancel</button>
            <button type="submit" form="q-form" class="btn-primary" wire:loading.attr="disabled">Create quotation</button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal wire:model="openId" :title="'Quotation '.($detail['number'] ?? '')" size="xl">
        @if ($detail)
            <div class="mb-4 flex flex-wrap items-center gap-3 text-sm"><x-ui.badge :status="$detail['status']"/><span class="font-medium">{{ $customers[$detail['customer_id']] ?? '' }}</span>
                <span class="text-slate-500">valid until {{ $detail['valid_until'] ? fmt()->date($detail['valid_until']) : '—' }}</span></div>
            @include('livewire.partials.sales-lines', ['document' => $detail])
            @if ($detail['sales_order_id'])<a href="{{ route('sales.orders', ['open' => $detail['sales_order_id']]) }}" class="link mt-3 inline-block text-sm">Open the sales order</a>@endif
        @endif
        <x-slot:footer>
            @if ($detail && ! $detail['sales_order_id'] && in_array($detail['status'], ['draft', 'sent'], true) && $can['order'])
                <button type="button" class="btn-primary" wire:click="convert">Convert to sales order</button>
            @endif
        </x-slot:footer>
    </x-ui.modal>
</div>

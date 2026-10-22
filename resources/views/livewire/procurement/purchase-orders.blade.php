<div>
    <x-ui.toolbar>
        <x-ui.field label="Status" for="status">
            <select id="status" wire:model.live="status" class="form-select w-48">
                <option value="">All</option>@foreach (['draft', 'pending_approval', 'approved', 'sent', 'partially_received', 'received', 'closed', 'rejected', 'cancelled'] as $s)<option value="{{ $s }}">{{ ucfirst(str_replace('_', ' ', $s)) }}</option>@endforeach
            </select>
        </x-ui.field>
        <x-ui.field label="Supplier" for="supplier">
            <select id="supplier" wire:model.live="supplier" class="form-select w-56"><option value="">All suppliers</option>@foreach ($suppliers as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
        </x-ui.field>
        <x-slot:actions>
            @if ($can['order'])<button type="button" class="btn-primary" wire:click="create"><x-heroicon-o-plus class="size-4"/>New purchase order</button>@endif
        </x-slot:actions>
    </x-ui.toolbar>

    <div class="card overflow-hidden">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Number</th><th>Supplier</th><th>Deliver to</th><th>Ordered</th><th>Expected</th><th class="num">Total</th><th>Status</th></tr></thead>
                <tbody>
                    @forelse ($rows as $o)
                        <tr wire:key="o-{{ $o['id'] }}" class="cursor-pointer" wire:click="open('{{ $o['id'] }}')">
                            <td class="font-mono text-xs font-semibold">{{ $o['number'] }}</td>
                            <td class="font-medium text-navy-900">{{ $suppliers[$o['supplier_id']] ?? '' }}</td>
                            <td>{{ $warehouses[$o['warehouse_id']] ?? '' }}</td>
                            <td>{{ $o['order_date'] ? fmt()->date($o['order_date']) : '—' }}</td>
                            <td>{{ $o['expected_date'] ? fmt()->date($o['expected_date']) : '—' }}</td>
                            <td class="num font-semibold">{{ fmt()->money($o['total'], $o['currency_code']) }}</td>
                            <td><x-ui.badge :status="$o['status']"/></td>
                        </tr>
                    @empty
                        <x-ui.empty colspan="7" icon="shopping-cart" title="No purchase orders"/>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-ui.pager :paginator="$meta"/>
    </div>

    <x-ui.modal wire:model="doc" :title="($doc['id'] ?? null) ? 'Edit purchase order' : 'New purchase order'" size="xl">
        @if ($doc)
            <form wire:submit="save" id="po-form" class="space-y-5">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <x-ui.field label="Supplier" for="doc.supplier_id">
                        <select id="doc.supplier_id" wire:model="doc.supplier_id" class="form-select" @disabled($doc['id'])><option value="">Choose…</option>@foreach ($suppliers as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
                    </x-ui.field>
                    <x-ui.field label="Deliver to" for="doc.warehouse_id">
                        <select id="doc.warehouse_id" wire:model="doc.warehouse_id" class="form-select" @disabled($doc['id'])><option value="">Choose…</option>@foreach ($warehouses as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
                    </x-ui.field>
                    <x-ui.field label="Expected delivery" for="doc.expected_date"><input id="doc.expected_date" type="date" wire:model="doc.expected_date" class="form-input"></x-ui.field>
                    <x-ui.field label="Notes" for="doc.notes"><input id="doc.notes" wire:model="doc.notes" class="form-input"></x-ui.field>
                </div>
                @include('livewire.partials.document-lines', ['form' => 'doc', 'priceLabel' => 'Unit cost'])
                <p class="text-xs text-slate-500">Leave the cost empty to use the supplier's agreed price. Totals are calculated when you save.</p>
            </form>
        @endif
        <x-slot:footer>
            <button type="button" class="btn-light" @click="open = null">Cancel</button>
            <button type="submit" form="po-form" class="btn-primary" wire:loading.attr="disabled">Save draft</button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal wire:model="openId" :title="'Purchase order '.($detail['number'] ?? '')" size="xl">
        @if ($detail)
            <div class="mb-4 flex flex-wrap items-start justify-between gap-4">
                <x-ui.dl :cols="3" class="flex-1" :items="[
                    'Supplier' => $suppliers[$detail['supplier_id']] ?? '', 'Deliver to' => $warehouses[$detail['warehouse_id']] ?? '',
                    'Status' => str_replace('_', ' ', $detail['status']), 'Ordered' => $detail['order_date'] ? fmt()->date($detail['order_date']) : null,
                    'Expected' => $detail['expected_date'] ? fmt()->date($detail['expected_date']) : null, 'Payment terms' => $detail['payment_terms_days'].' days',
                ]"/>
            </div>
            <div class="table-wrap rounded-lg border border-slate-200">
                <table class="data-table">
                    <thead><tr><th>Product</th><th>Pack</th><th class="num">Qty</th><th class="num">Unit cost</th><th class="num">Tax %</th><th class="num">Amount</th><th class="num">Received</th><th class="num">Invoiced</th></tr></thead>
                    <tbody>
                        @foreach ($detail['lines'] as $l)
                            <tr>
                                <td class="font-medium text-navy-900">{{ $names[$l['product_id']] ?? '' }}</td><td>{{ $l['package_level_code'] }}</td>
                                <td class="num">{{ fmt()->number($l['quantity'], 2) }}</td><td class="num">{{ fmt()->number($l['unit_price'], 2) }}</td><td class="num">{{ fmt()->number($l['tax_rate'], 2) }}</td>
                                <td class="num">{{ fmt()->number((float) $l['line_subtotal'] + (float) $l['line_tax'], 2) }}</td>
                                <td class="num">{{ fmt()->number($l['received_base_quantity'], 2) }} <span class="muted">/ {{ fmt()->number($l['base_quantity'], 2) }}</span></td>
                                <td class="num">{{ fmt()->number($l['invoiced_base_quantity'], 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="text-sm">
                        <tr class="border-t border-slate-200"><td colspan="5" class="px-4 py-1.5 text-right text-slate-500">Subtotal</td><td class="num px-4 py-1.5">{{ fmt()->money($detail['subtotal'], $detail['currency_code']) }}</td><td colspan="2"></td></tr>
                        <tr><td colspan="5" class="px-4 py-1.5 text-right text-slate-500">Tax</td><td class="num px-4 py-1.5">{{ fmt()->money($detail['tax_total'], $detail['currency_code']) }}</td><td colspan="2"></td></tr>
                        <tr class="font-semibold"><td colspan="5" class="px-4 py-2 text-right">Total</td><td class="num px-4 py-2">{{ fmt()->money($detail['total'], $detail['currency_code']) }}</td><td colspan="2"></td></tr>
                    </tfoot>
                </table>
            </div>
            @if ($detail['status_reason'] ?? null)<p class="mt-3 text-sm text-slate-600"><strong>Reason:</strong> {{ $detail['status_reason'] }}</p>@endif
            @if (! empty($detail['goods_receipts']))
                <p class="mt-4 text-sm"><strong>Goods receipts:</strong>
                    @foreach ($detail['goods_receipts'] as $gr)<a href="{{ route('procurement.receipts', ['order' => $detail['id']]) }}" class="link ml-2 font-mono text-xs">{{ $gr['number'] }}</a>@endforeach</p>
            @endif

            @if ($reasoned)
                <form wire:submit="act('{{ $reasoned['action'] }}')" class="mt-4 space-y-3 rounded-lg border border-slate-200 bg-slate-50 p-4">
                    <x-ui.field :label="'Reason to '.$reasoned['action']" for="reasoned.reason"><textarea id="reasoned.reason" wire:model="reasoned.reason" rows="2" class="form-input"></textarea></x-ui.field>
                    <div class="flex justify-end gap-2"><button type="button" class="btn-light btn-sm" wire:click="$set('reasoned', null)">Back</button><button class="btn-danger btn-sm">Confirm {{ $reasoned['action'] }}</button></div>
                </form>
            @endif
        @endif
        <x-slot:footer>
            @if ($detail && ! $reasoned)
                @php $s = $detail['status']; @endphp
                @if ($s === 'draft' && $can['order'])
                    <button type="button" class="btn-light" wire:click="edit">Edit</button>
                    <button type="button" class="btn-primary" wire:click="act('submit')">Submit</button>
                @endif
                @if ($s === 'pending_approval' && $can['approve'])
                    <button type="button" class="btn-danger" wire:click="act('reject')">Reject</button>
                    <button type="button" class="btn-primary" wire:click="act('approve')">Approve</button>
                @endif
                @if (in_array($s, ['draft', 'pending_approval', 'approved', 'sent', 'rejected'], true) && $can['order'])
                    <button type="button" class="btn-danger" wire:click="act('cancel')">Cancel order</button>
                @endif
                @if ($s === 'approved' && $can['order'])
                    <button type="button" class="btn-primary" wire:click="act('send')">Mark as sent</button>
                @endif
                @if (in_array($s, ['approved', 'sent', 'partially_received'], true) && $can['receive'])
                    <a href="{{ route('procurement.receipts', ['order' => $detail['id']]) }}" class="btn-primary"><x-heroicon-o-inbox-arrow-down class="size-4"/>Receive goods</a>
                @endif
                @if (in_array($s, ['sent', 'partially_received', 'received'], true) && $can['invoice'])
                    <a href="{{ route('procurement.invoices', ['order' => $detail['id']]) }}" class="btn-light"><x-heroicon-o-document-text class="size-4"/>Record invoice</a>
                @endif
                @if (in_array($s, ['partially_received', 'received'], true) && $can['order'])
                    <button type="button" class="btn-light" wire:click="act('close')">Close order</button>
                @endif
            @endif
        </x-slot:footer>
    </x-ui.modal>
</div>

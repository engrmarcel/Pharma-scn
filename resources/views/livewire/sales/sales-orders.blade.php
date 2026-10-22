<div>
    <x-ui.toolbar>
        <x-ui.field label="Status" for="status">
            <select id="status" wire:model.live="status" class="form-select w-48">
                <option value="">All</option>@foreach (['draft', 'pending_approval', 'confirmed', 'partially_delivered', 'delivered', 'closed', 'rejected', 'cancelled'] as $s)<option value="{{ $s }}">{{ ucfirst(str_replace('_', ' ', $s)) }}</option>@endforeach
            </select>
        </x-ui.field>
        <x-ui.field label="Customer" for="customer">
            <select id="customer" wire:model.live="customer" class="form-select w-56"><option value="">All customers</option>@foreach ($customers as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
        </x-ui.field>
        <x-slot:actions>
            @if ($can['order'])<button type="button" class="btn-primary" wire:click="create"><x-heroicon-o-plus class="size-4"/>New sales order</button>@endif
        </x-slot:actions>
    </x-ui.toolbar>

    <div class="card overflow-hidden">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Number</th><th>Customer</th><th>Warehouse</th><th>Ordered</th><th class="num">Total</th><th>Status</th></tr></thead>
                <tbody>
                    @forelse ($rows as $o)
                        <tr wire:key="o-{{ $o['id'] }}" class="cursor-pointer" wire:click="open('{{ $o['id'] }}')">
                            <td class="font-mono text-xs font-semibold">{{ $o['number'] }}</td>
                            <td class="font-medium text-navy-900">{{ $customers[$o['customer_id']] ?? '' }}</td>
                            <td>{{ $warehouses[$o['warehouse_id']] ?? '' }}</td>
                            <td>{{ $o['order_date'] ? fmt()->date($o['order_date']) : fmt()->date($o['created_at']) }}</td>
                            <td class="num font-semibold">{{ fmt()->money($o['total'], $o['currency_code']) }}</td>
                            <td><x-ui.badge :status="$o['status']"/></td>
                        </tr>
                    @empty
                        <x-ui.empty colspan="6" icon="shopping-bag" title="No sales orders"/>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-ui.pager :paginator="$meta"/>
    </div>

    <x-ui.modal wire:model="doc" :title="($doc['id'] ?? null) ? 'Edit sales order' : 'New sales order'" size="xl">
        @if ($doc)
            <form wire:submit="save" id="so-form" class="space-y-5">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <x-ui.field label="Customer" for="doc.customer_id">
                        <select id="doc.customer_id" wire:model="doc.customer_id" class="form-select" @disabled($doc['id'])><option value="">Choose…</option>@foreach ($customers as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
                    </x-ui.field>
                    <x-ui.field label="Ship from" for="doc.warehouse_id">
                        <select id="doc.warehouse_id" wire:model="doc.warehouse_id" class="form-select" @disabled($doc['id'])><option value="">Choose…</option>@foreach ($warehouses as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
                    </x-ui.field>
                    @unless ($doc['id'])
                        <x-ui.field label="Requested delivery" for="doc.requested_date"><input id="doc.requested_date" type="date" wire:model="doc.requested_date" class="form-input"></x-ui.field>
                        <x-ui.field label="Notes" for="doc.notes"><input id="doc.notes" wire:model="doc.notes" class="form-input"></x-ui.field>
                    @endunless
                </div>
                @include('livewire.partials.document-lines', ['form' => 'doc', 'rateField' => 'discount_percent', 'rateLabel' => 'Disc %'])
                <p class="text-xs text-slate-500">Leave the price empty to use the customer's price list. Tax, discount limits and credit checks are applied when you save and confirm.</p>
            </form>
        @endif
        <x-slot:footer>
            <button type="button" class="btn-light" @click="open = null">Cancel</button>
            <button type="submit" form="so-form" class="btn-primary" wire:loading.attr="disabled">Save draft</button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal wire:model="openId" :title="'Sales order '.($detail['number'] ?? '')" size="xl">
        @if ($detail)
            <div class="mb-4 flex flex-wrap items-center gap-3 text-sm">
                <x-ui.badge :status="$detail['status']"/>
                <span class="font-medium text-navy-900">{{ $customers[$detail['customer_id']] ?? '' }}</span>
                <span class="text-slate-500">from {{ $warehouses[$detail['warehouse_id']] ?? '' }} · terms {{ $detail['payment_terms_days'] }} days</span>
            </div>
            @if (! empty($detail['approval_reasons']) && $detail['status'] === 'pending_approval')
                <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">Needs approval: {{ collect($detail['approval_reasons'])->map(fn ($r) => is_array($r) ? ($r['reason'] ?? json_encode($r)) : str_replace('_', ' ', $r))->implode('; ') }}</div>
            @endif
            @include('livewire.partials.sales-lines', ['document' => $detail, 'progress' => true])
            @if ($detail['status_reason'] ?? null)<p class="mt-3 text-sm text-slate-600"><strong>Reason:</strong> {{ $detail['status_reason'] }}</p>@endif

            @if (! empty($detail['deliveries']) || ! empty($detail['invoices']))
                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <div>
                        <p class="mb-1 text-xs font-semibold uppercase text-slate-400">Deliveries</p>
                        @forelse ($detail['deliveries'] as $d)
                            <p class="text-sm"><span class="font-mono text-xs">{{ $d['number'] }}</span> · {{ fmt()->dateTime($d['delivered_at']) }}
                                <span class="muted block">{{ collect($d['lines'])->map(fn ($l) => ($batches[$l['batch_id']] ?? '').' × '.fmt()->number($l['base_quantity'], 2))->implode(', ') }}</span></p>
                        @empty<p class="text-sm text-slate-500">None yet.</p>@endforelse
                    </div>
                    <div>
                        <p class="mb-1 text-xs font-semibold uppercase text-slate-400">Invoices</p>
                        @forelse ($detail['invoices'] as $inv)
                            <a href="{{ route('sales.invoices', ['open' => $inv['id']]) }}" class="link block text-sm"><span class="font-mono text-xs">{{ $inv['number'] }}</span> · {{ fmt()->money($inv['total'], $detail['currency_code']) }} · {{ $inv['payment_status'] }}</a>
                        @empty<p class="text-sm text-slate-500">None yet.</p>@endforelse
                    </div>
                </div>
            @endif

            @if ($delivering !== null)
                <div class="mt-5 rounded-lg border border-slate-200 bg-slate-50 p-4">
                    <p class="mb-2 text-sm font-semibold text-navy-950">Deliver now <span class="font-normal text-slate-500">(in each line's ordered pack)</span></p>
                    @error('delivery.lines') <p class="mb-2 text-xs text-rose-600">{{ $message }}</p> @enderror
                    @foreach ($detail['lines'] as $l)
                        <div class="flex items-center justify-between gap-3 py-1 text-sm"><span>{{ $names[$l['product_id']] ?? '' }} · {{ $l['package_level_code'] }}</span>
                            <input wire:model="delivering.{{ $l['id'] }}" inputmode="decimal" class="form-input w-28 py-1.5 text-right" aria-label="Deliver"></div>
                    @endforeach
                    <input wire:model="deliveryNotes" class="form-input mt-2 py-2" placeholder="Delivery notes (optional)">
                    <div class="mt-3 flex justify-end gap-2"><button type="button" class="btn-light btn-sm" wire:click="$set('delivering', null)">Back</button><button type="button" class="btn-primary btn-sm" wire:click="deliver">Post delivery</button></div>
                </div>
            @endif

            @if ($reasoned)
                <form wire:submit="act('{{ $reasoned['action'] }}')" class="mt-4 space-y-3 rounded-lg border border-slate-200 bg-slate-50 p-4">
                    <x-ui.field :label="'Reason to '.$reasoned['action']" for="reasoned.reason"><textarea id="reasoned.reason" wire:model="reasoned.reason" rows="2" class="form-input"></textarea></x-ui.field>
                    <div class="flex justify-end gap-2"><button type="button" class="btn-light btn-sm" wire:click="$set('reasoned', null)">Back</button><button class="btn-danger btn-sm">Confirm {{ $reasoned['action'] }}</button></div>
                </form>
            @endif
        @endif
        <x-slot:footer>
            @if ($detail && ! $reasoned && $delivering === null)
                @php $s = $detail['status']; @endphp
                @if ($s === 'draft' && $can['order'])
                    <button type="button" class="btn-light" wire:click="edit">Edit lines</button>
                    <button type="button" class="btn-primary" wire:click="act('confirm')">Confirm order</button>
                @endif
                @if ($s === 'pending_approval' && $can['approve'])
                    <button type="button" class="btn-danger" wire:click="act('reject')">Reject</button>
                    <button type="button" class="btn-primary" wire:click="act('approve')">Approve</button>
                @endif
                @if (in_array($s, ['draft', 'pending_approval', 'confirmed'], true) && $can['order'])<button type="button" class="btn-danger" wire:click="act('cancel')">Cancel order</button>@endif
                @if (in_array($s, ['confirmed', 'partially_delivered'], true) && $can['deliver'])<button type="button" class="btn-primary" wire:click="startDelivery"><x-heroicon-o-truck class="size-4"/>Deliver</button>@endif
                @if (in_array($s, ['partially_delivered', 'delivered'], true) && $can['invoice'])<button type="button" class="btn-primary" wire:click="invoice"><x-heroicon-o-document-text class="size-4"/>Invoice delivered goods</button>@endif
                @if (in_array($s, ['partially_delivered', 'delivered'], true) && $can['order'])<button type="button" class="btn-light" wire:click="act('close')">Close order</button>@endif
            @endif
        </x-slot:footer>
    </x-ui.modal>
</div>

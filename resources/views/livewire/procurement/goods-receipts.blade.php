<div class="space-y-6">
    <x-ui.card title="Receive against a purchase order" icon="inbox-arrow-down">
        <div class="grid gap-4 sm:grid-cols-3">
            <x-ui.field label="Purchase order" for="order" class="sm:col-span-1">
                <select id="order" wire:model.live="order" class="form-select"><option value="">Choose an open order…</option>@foreach ($orders as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach</select>
            </x-ui.field>
            @if ($lines)
                <x-ui.field label="Supplier delivery note" for="receipt.supplier_delivery_note"><input id="receipt.supplier_delivery_note" wire:model="form.supplier_delivery_note" class="form-input"></x-ui.field>
                <x-ui.field label="Notes" for="receipt.notes"><input id="receipt.notes" wire:model="form.notes" class="form-input"></x-ui.field>
            @endif
        </div>

        @if ($order && ! $lines)
            <p class="mt-4 text-sm text-slate-500">This order is not open for receiving.</p>
        @elseif ($lines)
            @error('receipt.lines') <p class="mt-3 text-xs text-rose-600">{{ $message }}</p> @enderror
            <div class="mt-4 space-y-3">
                @foreach ($lines as $i => $l)
                    <div class="grid items-start gap-2 rounded-xl border border-slate-200 p-3 md:grid-cols-6" wire:key="gl-{{ $i }}">
                        <div class="md:col-span-6 flex flex-wrap items-baseline justify-between gap-2">
                            <span class="font-medium text-navy-900">{{ $l['name'] }} <span class="text-sm font-normal text-slate-500">· {{ $l['pack'] }}</span></span>
                            <span class="text-xs text-slate-500">Outstanding {{ fmt()->number($l['outstanding'], 2) }}</span>
                        </div>
                        <x-ui.field label="Batch" :for="'receipt.lines.'.$i.'.batch_number'"><input wire:model="lines.{{ $i }}.batch_number" class="form-input py-2 font-mono uppercase"></x-ui.field>
                        <x-ui.field label="Expiry" :for="'receipt.lines.'.$i.'.expiry_date'"><input type="date" wire:model="lines.{{ $i }}.expiry_date" class="form-input py-2"></x-ui.field>
                        <x-ui.field label="Manufactured" :for="'receipt.lines.'.$i.'.manufacturing_date'"><input type="date" wire:model="lines.{{ $i }}.manufacturing_date" class="form-input py-2"></x-ui.field>
                        <x-ui.field label="Quantity received" :for="'receipt.lines.'.$i.'.quantity'"><input wire:model="lines.{{ $i }}.quantity" inputmode="decimal" class="form-input py-2"></x-ui.field>
                        <x-ui.field label="Quality" :for="'receipt.lines.'.$i.'.quality_status'">
                            <select wire:model="lines.{{ $i }}.quality_status" class="form-select py-2"><option value="released">Released</option><option value="quarantined">Quarantine first</option></select>
                        </x-ui.field>
                    </div>
                @endforeach
            </div>
            <p class="mt-2 text-xs text-slate-500">Leave a quantity empty to skip that line. Quantities are in each line's ordered pack.</p>
            @if ($canReceive)<div class="mt-4 flex justify-end"><button type="button" class="btn-primary" wire:click="receive" wire:loading.attr="disabled"><x-heroicon-o-check class="size-4"/>Post goods receipt</button></div>@endif
        @endif
    </x-ui.card>

    <div class="card overflow-hidden">
        <div class="card-header"><h2 class="card-title"><x-heroicon-o-list-bullet class="size-4 text-gold-500"/>{{ $order ? 'Receipts for this order' : 'Recent receipts' }}</h2></div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Number</th><th>Supplier</th><th>Warehouse</th><th>Received</th><th>Delivery note</th><th class="num">Lines</th></tr></thead>
                <tbody>
                    @forelse ($rows as $g)
                        <tr wire:key="g-{{ $g['id'] }}" class="cursor-pointer" wire:click="$set('viewing', @js($g))">
                            <td class="font-mono text-xs font-semibold">{{ $g['number'] }}</td>
                            <td>{{ $suppliers[$g['supplier_id']] ?? '' }}</td>
                            <td>{{ $warehouses[$g['warehouse_id']] ?? '' }}</td>
                            <td>{{ fmt()->dateTime($g['received_at']) }}</td>
                            <td>{{ $g['supplier_delivery_note'] ?? '—' }}</td>
                            <td class="num">{{ count($g['lines'] ?? []) }}</td>
                        </tr>
                    @empty
                        <x-ui.empty colspan="6" icon="inbox-arrow-down" title="No goods receipts"/>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-ui.pager :paginator="$meta"/>
    </div>

    <x-ui.modal wire:model="viewing" :title="'Goods receipt '.($viewing['number'] ?? '')" size="lg">
        @if ($viewing)
            <ul class="divide-y divide-slate-100 rounded-lg border border-slate-200 text-sm">
                @foreach ($viewing['lines'] ?? [] as $l)
                    <li class="flex flex-wrap justify-between gap-2 px-3 py-2"><span>{{ $names[$l['product_id']] ?? '' }} <span class="font-mono text-xs text-slate-500">{{ $batches[$l['batch_id']] ?? '' }}</span> <x-ui.badge :status="$l['quality_status']"/></span><span class="tabular-nums">{{ fmt()->number($l['quantity'], 2) }} <span class="muted">({{ fmt()->number($l['base_quantity'], 2) }} base)</span></span></li>
                @endforeach
            </ul>
        @endif
    </x-ui.modal>
</div>

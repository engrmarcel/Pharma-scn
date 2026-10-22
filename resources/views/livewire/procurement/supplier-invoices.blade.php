<div>
    <x-ui.toolbar>
        <x-ui.field label="Status" for="status">
            <select id="status" wire:model.live="status" class="form-select w-40"><option value="">All</option>@foreach (['draft', 'approved', 'cancelled'] as $s)<option value="{{ $s }}">{{ ucfirst($s) }}</option>@endforeach</select>
        </x-ui.field>
        <x-ui.field label="Match" for="match">
            <select id="match" wire:model.live="match" class="form-select w-40"><option value="">All</option>@foreach (['matched', 'variance', 'unmatched'] as $s)<option value="{{ $s }}">{{ ucfirst($s) }}</option>@endforeach</select>
        </x-ui.field>
        <x-slot:actions>
            @if ($can['record'])<button type="button" class="btn-primary" wire:click="create"><x-heroicon-o-plus class="size-4"/>Record invoice</button>@endif
        </x-slot:actions>
    </x-ui.toolbar>

    <div class="card overflow-hidden">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Number</th><th>Supplier invoice</th><th>Supplier</th><th>Date</th><th>Due</th><th class="num">Total</th><th>Match</th><th>Payment</th><th>Status</th></tr></thead>
                <tbody>
                    @forelse ($rows as $i)
                        <tr wire:key="i-{{ $i['id'] }}" class="cursor-pointer" wire:click="open('{{ $i['id'] }}')">
                            <td class="font-mono text-xs font-semibold">{{ $i['number'] }}</td>
                            <td class="font-mono text-xs">{{ $i['supplier_invoice_number'] }}</td>
                            <td>{{ $suppliers[$i['supplier_id']] ?? '' }}</td>
                            <td>{{ fmt()->date($i['invoice_date']) }}</td>
                            <td>{{ $i['due_date'] ? fmt()->date($i['due_date']) : '—' }}</td>
                            <td class="num font-semibold">{{ fmt()->money($i['total'], $i['currency_code']) }}</td>
                            <td><x-ui.badge :status="$i['match_status'] === 'matched' ? 'ok' : ($i['match_status'] === 'variance' ? 'warning' : 'failed')">{{ $i['match_status'] }}</x-ui.badge></td>
                            <td><x-ui.badge :status="$i['payment_status']"/></td>
                            <td><x-ui.badge :status="$i['status']"/></td>
                        </tr>
                    @empty
                        <x-ui.empty colspan="9" icon="document-text" title="No supplier invoices"/>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-ui.pager :paginator="$meta"/>
    </div>

    <x-ui.modal wire:model="doc" title="Record a supplier invoice" size="xl">
        @if ($doc)
            <form wire:submit="save" id="inv-form" class="space-y-5">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                    <x-ui.field label="Supplier" for="doc.supplier_id">
                        <select id="doc.supplier_id" wire:model.live="doc.supplier_id" class="form-select"><option value="">Choose…</option>@foreach ($suppliers as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
                    </x-ui.field>
                    <x-ui.field label="Purchase order" for="doc.purchase_order_id">
                        <select id="doc.purchase_order_id" wire:model="doc.purchase_order_id" wire:change="create($event.target.value)" class="form-select"><option value="">None</option>@foreach ($orders as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select>
                    </x-ui.field>
                    <x-ui.field label="Supplier's invoice no." for="doc.supplier_invoice_number"><input id="doc.supplier_invoice_number" wire:model="doc.supplier_invoice_number" class="form-input font-mono"></x-ui.field>
                    <x-ui.field label="Invoice date" for="doc.invoice_date"><input id="doc.invoice_date" type="date" wire:model="doc.invoice_date" class="form-input"></x-ui.field>
                    <x-ui.field label="Due date" for="doc.due_date"><input id="doc.due_date" type="date" wire:model="doc.due_date" class="form-input"></x-ui.field>
                </div>
                @error('doc.lines') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
                <div class="space-y-2">
                    @foreach ($doc['lines'] as $i => $l)
                        <div class="grid items-start gap-2 md:grid-cols-[minmax(0,1fr)_7rem_8rem_6rem_auto]" wire:key="il-{{ $i }}">
                            <x-ui.field :for="'doc.lines.'.$i.'.description'">
                                @if ($l['purchase_order_line_id'])<p class="form-input bg-slate-50 py-2 text-sm">{{ $l['description'] }}</p>
                                @else<input wire:model="doc.lines.{{ $i }}.description" class="form-input py-2" placeholder="Description (freight, services…)">@endif
                            </x-ui.field>
                            <x-ui.field :for="'doc.lines.'.$i.'.quantity'"><input wire:model="doc.lines.{{ $i }}.quantity" inputmode="decimal" class="form-input py-2" aria-label="Quantity"></x-ui.field>
                            <x-ui.field :for="'doc.lines.'.$i.'.unit_price'"><input wire:model="doc.lines.{{ $i }}.unit_price" inputmode="decimal" class="form-input py-2" aria-label="Unit price"></x-ui.field>
                            <x-ui.field :for="'doc.lines.'.$i.'.tax_rate'"><input wire:model="doc.lines.{{ $i }}.tax_rate" inputmode="decimal" class="form-input py-2" placeholder="Tax %" aria-label="Tax rate"></x-ui.field>
                            <button type="button" class="p-2 text-slate-400 hover:text-rose-600" wire:click="removeLine({{ $i }})" aria-label="Remove"><x-heroicon-o-trash class="size-4"/></button>
                        </div>
                    @endforeach
                    <button type="button" class="link text-sm" wire:click="addLine">+ Add a line without an order</button>
                </div>
                <p class="text-xs text-slate-500">Quantities for order lines are in the ordered pack and default to what has been received but not yet billed. The invoice is matched against the order and receipts when you save.</p>
            </form>
        @endif
        <x-slot:footer>
            <button type="button" class="btn-light" @click="open = null">Cancel</button>
            <button type="submit" form="inv-form" class="btn-primary" wire:loading.attr="disabled">Record invoice</button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal wire:model="openId" :title="'Supplier invoice '.($detail['number'] ?? '')" size="lg">
        @if ($detail)
            <x-ui.dl :cols="3" :items="['Supplier' => $suppliers[$detail['supplier_id']] ?? '', 'Their number' => $detail['supplier_invoice_number'], 'Status' => $detail['status'],
                'Total' => fmt()->money($detail['total'], $detail['currency_code']), 'Paid' => fmt()->money($detail['amount_paid'], $detail['currency_code']), 'Match' => $detail['match_status']]"/>
            @if (! empty($detail['match_details']))
                <div class="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                    <p class="font-semibold">Matching found:</p>
                    <ul class="mt-1 list-disc pl-5">@foreach ($detail['match_details'] as $m)<li>{{ str_replace('_', ' ', $m['issue'] ?? json_encode($m)) }}@if (isset($m['line'])) (line {{ $m['line'] }})@endif</li>@endforeach</ul>
                </div>
            @endif
            <ul class="mt-4 divide-y divide-slate-100 rounded-lg border border-slate-200 text-sm">
                @foreach ($detail['lines'] ?? [] as $l)
                    <li class="flex justify-between gap-3 px-3 py-2"><span>{{ $l['description'] ?? 'Order line '.$l['purchase_order_line_id'] }}</span><span class="tabular-nums">{{ fmt()->number($l['quantity'], 2) }} × {{ fmt()->number($l['unit_price'], 2) }}</span></li>
                @endforeach
            </ul>
            @if ($detail['status'] === 'draft')
                <x-ui.field :label="$detail['match_status'] === 'matched' ? 'Reason (needed only to cancel)' : 'Override or cancellation reason'" for="approve.override_reason" class="mt-4">
                    <textarea id="approve.override_reason" wire:model="reason" rows="2" class="form-input"></textarea>
                    @error('approve.reason') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </x-ui.field>
            @endif
        @endif
        <x-slot:footer>
            @if ($detail && $detail['status'] === 'draft')
                @if ($can['record'])<button type="button" class="btn-danger" wire:click="cancel">Cancel invoice</button>@endif
                @if ($can['approve'])<button type="button" class="btn-primary" wire:click="approve">Approve</button>@endif
            @endif
        </x-slot:footer>
    </x-ui.modal>
</div>

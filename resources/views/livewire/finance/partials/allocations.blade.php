{{-- Allocation inputs against open supplier invoices. Needs: $openInvoices, $model (wire path of the invoice id => amount map), $error. --}}
<div>
    <p class="mb-2 text-sm font-semibold text-navy-950">Allocate to approved invoices</p>
    @error($error) <p class="mb-2 text-xs text-rose-600">{{ $message }}</p> @enderror
    <div class="table-wrap rounded-lg border border-slate-200">
        <table class="data-table">
            <thead><tr><th>Invoice</th><th>Due</th><th class="num">Open</th><th class="num">Allocate</th></tr></thead>
            <tbody>
                @forelse ($openInvoices as $inv)
                    <tr wire:key="al-{{ $inv['id'] }}"><td class="font-mono text-xs">{{ $inv['number'] }} <span class="text-slate-400">({{ $inv['supplier_invoice_number'] }})</span></td>
                        <td>{{ $inv['due_date'] ? fmt()->date($inv['due_date']) : '—' }}</td><td class="num">{{ fmt()->money($inv['open'], $inv['currency_code']) }}</td>
                        <td class="num"><input wire:model="{{ $model }}.{{ $inv['id'] }}" inputmode="decimal" class="form-input ml-auto w-32 py-1.5 text-right" aria-label="Allocate"></td></tr>
                @empty
                    <x-ui.empty colspan="4" icon="check-circle" title="No approved invoices open">The payment is held on account until you allocate it.</x-ui.empty>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

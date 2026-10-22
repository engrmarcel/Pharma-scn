{{-- Priced lines with totals for a sales document. Needs: $document (with lines, subtotal, discount_total, tax_total, total, currency_code), $names; optional $progress (show delivered/invoiced). --}}
@php $progress ??= false; $cur = $document['currency_code']; @endphp
<div class="table-wrap rounded-lg border border-slate-200">
    <table class="data-table">
        <thead><tr><th>Product</th><th>Pack</th><th class="num">Qty</th><th class="num">Price</th><th class="num">Disc %</th><th class="num">Tax %</th><th class="num">Line total</th>@if ($progress)<th class="num">Delivered</th><th class="num">Invoiced</th>@endif</tr></thead>
        <tbody>
            @foreach ($document['lines'] as $l)
                <tr>
                    <td class="font-medium text-navy-900">{{ $names[$l['product_id']] ?? '' }}</td>
                    <td>{{ $l['package_level_code'] }}</td>
                    <td class="num">{{ fmt()->number($l['quantity'], 2) }}</td>
                    <td class="num">{{ fmt()->number($l['unit_price'], 2) }}@if ((float) ($l['list_price'] ?? 0) && (float) $l['list_price'] !== (float) $l['unit_price'])<span class="muted block">list {{ fmt()->number($l['list_price'], 2) }}</span>@endif</td>
                    <td class="num">{{ (float) $l['discount_percent'] ? fmt()->number($l['discount_percent'], 2) : '—' }}</td>
                    <td class="num">{{ fmt()->number($l['tax_rate'], 2) }}</td>
                    <td class="num font-medium">{{ fmt()->number($l['line_total'], 2) }}</td>
                    @if ($progress)
                        <td class="num">{{ fmt()->number($l['delivered_base_quantity'] ?? 0, 2) }} <span class="muted">/ {{ fmt()->number($l['base_quantity'], 2) }}</span></td>
                        <td class="num">{{ fmt()->number($l['invoiced_base_quantity'] ?? 0, 2) }}</td>
                    @endif
                </tr>
            @endforeach
        </tbody>
        <tfoot class="text-sm">
            @foreach (['Subtotal' => 'subtotal', 'Discount' => 'discount_total', 'Tax' => 'tax_total'] as $label => $key)
                @if (isset($document[$key]))
                    <tr><td colspan="6" class="px-4 py-1.5 text-right text-slate-500">{{ $label }}</td><td class="num px-4 py-1.5">{{ fmt()->money($document[$key], $cur) }}</td>@if ($progress)<td colspan="2"></td>@endif</tr>
                @endif
            @endforeach
            <tr class="border-t border-slate-200 font-semibold"><td colspan="6" class="px-4 py-2 text-right">Total</td><td class="num px-4 py-2">{{ fmt()->money($document['total'], $cur) }}</td>@if ($progress)<td colspan="2"></td>@endif</tr>
        </tfoot>
    </table>
</div>

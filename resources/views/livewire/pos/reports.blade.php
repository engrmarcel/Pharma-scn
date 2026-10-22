<div>
    <x-ui.tabs :current="$report" model="report" :tabs="$reports"/>
    <x-ui.toolbar>
        <x-ui.field label="From" for="from"><input id="from" type="date" wire:model.live="from" class="form-input w-44"></x-ui.field>
        <x-ui.field label="To" for="to"><input id="to" type="date" wire:model.live="to" class="form-input w-44"></x-ui.field>
        <x-slot:actions><button type="button" class="btn-light" onclick="window.print()"><x-heroicon-o-printer class="size-4"/>Print</button></x-slot:actions>
    </x-ui.toolbar>

    @if ($data === null)
        <x-ui.card><x-ui.empty icon="chart-bar" title="Report not available"/></x-ui.card>
    @elseif ($report === 'summary')
        @php $s = $data['rows']; @endphp
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-ui.stat label="Sales" :value="number_format($s['sales_count'])"/>
            <x-ui.stat label="Net sales" :value="fmt()->money($s['net_sales'], $currency)" tone="green" :hint="'Gross '.fmt()->money($s['gross_sales'], $currency).' less discounts '.fmt()->money($s['discounts'], $currency)"/>
            <x-ui.stat label="Collected" :value="fmt()->money($s['total_collected'], $currency)" tone="gold" :hint="'incl. tax '.fmt()->money($s['tax'], $currency)"/>
            <x-ui.stat label="Refunds" :value="fmt()->money($s['refunds'], $currency)" tone="red"/>
            <x-ui.stat label="Cost of goods" :value="fmt()->money($s['cost_of_goods'], $currency)"/>
            <x-ui.stat label="Gross margin" :value="fmt()->money($s['gross_margin'], $currency)" tone="green"/>
            <x-ui.stat label="Units without cost" :value="fmt()->number($s['uncosted_units'], 2)" tone="amber"/>
        </div>
        <p class="mt-3 text-xs text-slate-500">{{ $s['note'] }}</p>
    @else
        <div class="card overflow-hidden">
            <div class="table-wrap">
                <table class="data-table">
                    @php $cols = array_keys($rows[0] ?? []); $hidden = ['product_id', 'cashier_id', 'pos_terminal_id', 'branch_id', 'batch_id', 'pos_sale_id', 'requested_by', 'decided_by', 'reviewed_by']; @endphp
                    <thead><tr>
                        @if ($report === 'batches')<th>Product</th>@endif
                        @foreach (array_diff($cols, $hidden) as $c)<th class="{{ in_array($c, ['sales', 'total', 'net_sales', 'tax', 'base_quantity', 'payments', 'amount', 'opening_float', 'expected_cash', 'counted_cash', 'variance'], true) ? 'num' : '' }}">{{ str_replace('_', ' ', $c) }}</th>@endforeach
                    </tr></thead>
                    <tbody>
                        @forelse ($rows as $r)
                            <tr>
                                @if ($report === 'batches')<td class="font-medium text-navy-900">{{ $names[$r['product_id']] ?? '' }}</td>@endif
                                @foreach (array_diff($cols, $hidden) as $c)
                                    @php $v = $r[$c]; @endphp
                                    @if (is_numeric($v) && ! in_array($c, ['number', 'code', 'batch_number'], true))
                                        <td class="num">{{ fmt()->number($v, 2) }}</td>
                                    @elseif ($v && in_array($c, ['opened_at', 'closed_at', 'created_at'], true))
                                        <td>{{ fmt()->dateTime($v) }}</td>
                                    @else
                                        <td>{{ is_string($v) ? str_replace('_', ' ', $v) : ($v ?? '—') }}</td>
                                    @endif
                                @endforeach
                            </tr>
                        @empty
                            <x-ui.empty colspan="8" icon="chart-bar" title="Nothing in this period"/>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>

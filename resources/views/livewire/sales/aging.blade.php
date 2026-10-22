<div>
    <x-ui.toolbar>
        <x-ui.field label="As of" for="asOf"><input id="asOf" type="date" wire:model.live="asOf" class="form-input w-44"></x-ui.field>
    </x-ui.toolbar>

    @if ($aging === null)
        <x-ui.card><x-ui.empty icon="lock-closed" title="Organization-wide receivables are not available to you">Members limited to their assigned customers see balances on each customer's statement.</x-ui.empty></x-ui.card>
    @else
        <div class="mb-6 grid gap-4 sm:grid-cols-3 lg:grid-cols-5">
            @foreach (['current' => ['Not yet due', 'green'], '1_30' => ['1–30 days', 'gold'], '31_60' => ['31–60 days', 'amber'], '61_90' => ['61–90 days', 'amber'], 'over_90' => ['Over 90 days', 'red']] as $key => [$label, $tone])
                <x-ui.stat :label="$label" :value="fmt()->money($aging['buckets'][$key], $currency)" :tone="$tone"/>
            @endforeach
        </div>
        <div class="card overflow-hidden">
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Invoice</th><th>Customer</th><th>Due</th><th class="num">Days past due</th><th class="num">Total</th><th class="num">Open</th><th>Bucket</th></tr></thead>
                    <tbody>
                        @forelse (collect($aging['invoices'])->sortByDesc('days_past_due') as $i)
                            <tr><td><a href="{{ route('sales.invoices', ['open' => $i['id']]) }}" class="link font-mono text-xs">{{ $i['number'] }}</a></td><td class="font-medium text-navy-900">{{ $i['customer_name'] }}</td>
                                <td>{{ $i['due_date'] ? fmt()->date($i['due_date']) : '—' }}</td><td class="num {{ $i['days_past_due'] > 60 ? 'font-semibold text-rose-700' : '' }}">{{ $i['days_past_due'] }}</td>
                                <td class="num">{{ fmt()->money($i['total'], $i['currency_code']) }}</td><td class="num font-semibold">{{ fmt()->money($i['open_amount'], $i['currency_code']) }}</td>
                                <td><x-ui.badge :tone="$i['bucket'] === 'current' ? 'green' : ($i['bucket'] === 'over_90' ? 'red' : 'amber')">{{ str_replace('_', '–', $i['bucket']) }}</x-ui.badge></td></tr>
                        @empty
                            <x-ui.empty colspan="7" icon="check-circle" title="Nothing outstanding"/>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>

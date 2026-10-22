@php
    $s = $board['sections'];
    $first = fn ($money) => $money[0] ?? null;
    $amount = fn ($money) => (float) ($first($money)['amount'] ?? 0);
    $currency = fn ($money) => $first($money)['currency_code'] ?? null;
    $more = fn ($money) => count($money) > 1 ? ' (+'.(count($money) - 1).' other currencies)' : '';
    $labels = [
        'sales' => 'Sales', 'purchases' => 'Purchases', 'inventory' => 'Inventory', 'expiry_risk' => 'Expiry risk', 'pos' => 'Point of sale',
        'field_force' => 'Field force', 'marketplace' => 'Marketplace', 'integrations' => 'Integrations', 'sync' => 'Offline sync', 'recalls' => 'Recalls',
    ];

    $cards = [];
    if (isset($s['sales'])) {
        $cards[] = ['label' => 'Revenue', 'value' => $amount($s['sales']['revenue']), 'decimals' => 2, 'tone' => 'blue', 'icon' => 'banknotes',
            'currency' => $currency($s['sales']['revenue']), 'href' => null, 'hint' => $s['sales']['invoices'].' invoices'.$more($s['sales']['revenue'])];
        $cards[] = ['label' => 'Overdue receivables', 'value' => $amount($s['sales']['overdue']), 'decimals' => 2, 'tone' => $amount($s['sales']['overdue']) > 0 ? 'rose' : 'slate',
            'icon' => 'clock', 'currency' => $currency($s['sales']['overdue']), 'href' => null, 'hint' => 'of '.number_format($amount($s['sales']['receivables']), 2).' owed in total'];
    }
    if (isset($s['inventory'])) {
        $value = $s['inventory']['stock_value'];
        $cards[] = empty($value['withheld'])
            ? ['label' => 'Stock value', 'value' => $amount($value['by_currency']), 'decimals' => 2, 'tone' => 'emerald', 'icon' => 'cube', 'currency' => $currency($value['by_currency']),
                'href' => null, 'hint' => $s['inventory']['products_in_stock'].' products in stock']
            : ['label' => 'Products in stock', 'value' => $s['inventory']['products_in_stock'], 'decimals' => 0, 'tone' => 'emerald', 'icon' => 'cube', 'currency' => null,
                'href' => null, 'hint' => 'Stock value needs the valuation permission'];
        $cards[] = ['label' => 'Stockout risk', 'value' => $s['inventory']['stockout_risk'], 'decimals' => 0, 'tone' => $s['inventory']['stockout_risk'] ? 'amber' : 'slate',
            'icon' => 'exclamation-triangle', 'currency' => null, 'href' => null, 'hint' => $s['inventory']['below_reorder_level'].' products at or below reorder level'];
    }
    if (isset($s['expiry_risk'])) {
        $cards[] = ['label' => 'Batches at expiry risk', 'value' => $s['expiry_risk']['at_risk_batches'], 'decimals' => 0, 'tone' => $s['expiry_risk']['at_risk_batches'] ? 'rose' : 'slate',
            'icon' => 'calendar-days', 'currency' => null, 'href' => null, 'hint' => 'Prediction: unlikely to sell before expiry'];
    }
    if (isset($s['pos'])) {
        $cards[] = ['label' => 'POS sales', 'value' => $s['pos']['sales'], 'decimals' => 0, 'tone' => 'violet', 'icon' => 'building-storefront', 'currency' => null, 'href' => null,
            'hint' => $s['pos']['offline_percent'] === null ? 'No sales in the period' : $s['pos']['offline_percent'].'% captured offline'];
    }
    if (isset($s['recalls'])) {
        $open = ($s['recalls']['notices_by_status']['pending'] ?? 0) + ($s['recalls']['notices_by_status']['acknowledged'] ?? 0);
        $cards[] = ['label' => 'Open recall notices', 'value' => $open, 'decimals' => 0, 'tone' => $open ? 'rose' : 'slate', 'icon' => 'shield-exclamation', 'currency' => null,
            'href' => null, 'hint' => $s['recalls']['incidents']['overdue'].' regulatory incidents overdue'];
    }
    $trend = collect($s['sales']['trend'] ?? [])->where('currency_code', $currency($s['sales']['revenue'] ?? []));
    $peak = max(1, (float) $trend->max(fn ($r) => (float) $r['amount']));
@endphp

<div class="space-y-8">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h2 class="font-semibold text-navy-950">Executive dashboard</h2>
            <p class="mt-1 text-sm text-slate-500">
                Headline figures for {{ $board['locations'] === 'all' ? 'all locations' : $board['locations'].' warehouses you have access to' }}. Each area follows its own permissions.
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-3 text-sm text-slate-600">
            <label>From <input type="date" wire:model.live="from" class="form-field ml-1 px-3 py-2 text-sm" max="{{ $to }}"></label>
            <label>To <input type="date" wire:model.live="to" class="form-field ml-1 px-3 py-2 text-sm" max="{{ today()->toDateString() }}"></label>
        </div>
    </div>

    <section class="card px-5 py-4">
        <form wire:submit="ask" class="flex flex-col gap-3 sm:flex-row sm:items-center">
            <label for="assistant-question" class="sr-only">Ask about your figures</label>
            <input id="assistant-question" type="text" wire:model="question" maxlength="500" class="form-field flex-1 px-3 py-2 text-sm"
                   placeholder="Ask about your figures, e.g. &quot;What is at risk of expiring?&quot; or &quot;Sales last month&quot;">
            <button type="submit" class="btn-primary px-4 py-2 text-sm" wire:loading.attr="disabled" wire:target="ask">Ask</button>
        </form>
        @error('question') <p class="mt-2 text-sm text-rose-700">{{ $message }}</p> @enderror
        @if ($answer)
            <div class="mt-4 space-y-2 text-sm" aria-live="polite">
                <p class="text-xs uppercase tracking-wider text-slate-400">Generated answer · {{ $answer['planner'] }}</p>
                @foreach (explode("

", $answer['text']) as $paragraph)
                    <p class="text-slate-700">{{ $paragraph }}</p>
                @endforeach
                @if ($answer['caveats'])
                    <ul class="list-disc pl-5 text-xs text-slate-500">
                        @foreach ($answer['caveats'] as $caveat) <li>{{ $caveat }}</li> @endforeach
                    </ul>
                @endif
                <p class="text-xs text-slate-500">{{ $answer['label'] }} Reports used: {{ implode(', ', $answer['reports']) ?: 'none' }}.</p>
            </div>
        @endif
    </section>

    @error('from') <p class="text-sm text-rose-700">{{ $message }}</p> @enderror
    @error('to') <p class="text-sm text-rose-700">{{ $message }}</p> @enderror

    @if ($cards)
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ($cards as $i => $card)
                <x-stat-card :card="$card" :delay="$i * 60"/>
            @endforeach
        </div>
    @endif

    <div class="grid gap-6 xl:grid-cols-[2fr_1fr]">
        @isset($s['sales'])
            <section class="card overflow-hidden">
                <header class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
                    <h2 class="font-semibold text-navy-950">Revenue trend</h2>
                    <span class="text-xs text-slate-500">Issued invoices, {{ $currency($s['sales']['revenue']) ?? 'no sales' }}</span>
                </header>
                <div class="px-5 py-5">
                    @if ($trend->isEmpty())
                        <p class="text-sm text-slate-500">No invoices in this period.</p>
                    @else
                        <div class="flex h-48 items-end gap-1" role="img" aria-label="Revenue per period">
                            @foreach ($trend as $point)
                                <div class="group relative flex-1 rounded-t bg-blue-600/80 transition hover:bg-blue-700"
                                     style="height: {{ max(2, round((float) $point['amount'] / $peak * 100)) }}%"
                                     title="{{ $point['period'] }}: {{ number_format((float) $point['amount'], 2) }} ({{ $point['invoices'] }} invoices)"></div>
                            @endforeach
                        </div>
                        <div class="mt-2 flex justify-between text-xs text-slate-400">
                            <span>{{ $trend->first()['period'] }}</span><span>{{ $trend->last()['period'] }}</span>
                        </div>
                    @endif
                </div>
            </section>
        @endisset

        @isset($s['expiry_risk'])
            <section class="card overflow-hidden">
                <header class="border-b border-slate-100 px-5 py-4">
                    <h2 class="font-semibold text-navy-950">Stock by time to expiry</h2>
                    <p class="mt-1 text-xs text-slate-500">Base units, all stock states.</p>
                </header>
                <table class="min-w-full text-sm">
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($s['expiry_risk']['buckets'] as $bucket)
                            <tr>
                                <td class="px-5 py-3 text-slate-700">{{ $bucket['bucket'] === 'expired' ? 'Expired' : str_replace(['-', '_days'], [' to ', ' days'], $bucket['bucket']) }}</td>
                                <td class="px-3 py-3 text-right tabular-nums text-slate-500">{{ $bucket['batches'] }} batches</td>
                                <td class="px-5 py-3 text-right font-medium tabular-nums text-navy-950">{{ number_format((float) $bucket['quantity']) }}</td>
                            </tr>
                        @empty
                            <tr><td class="px-5 py-4 text-slate-500">Nothing expires within 180 days.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </section>
        @endisset
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        @isset($s['integrations'])
            <section class="card px-5 py-4">
                <h2 class="font-semibold text-navy-950">Integrations</h2>
                <dl class="mt-3 space-y-2 text-sm">
                    @forelse ($s['integrations']['connectors_by_health'] as $health => $n)
                        <div class="flex justify-between"><dt class="text-slate-600">{{ ucfirst(str_replace('_', ' ', $health)) }}</dt><dd class="tabular-nums">{{ $n }}</dd></div>
                    @empty
                        <p class="text-slate-500">No connectors.</p>
                    @endforelse
                    <div class="flex justify-between border-t border-slate-100 pt-2"><dt class="text-slate-600">Webhook success</dt>
                        <dd class="tabular-nums">{{ $s['integrations']['webhook_success_percent'] === null ? '—' : $s['integrations']['webhook_success_percent'].'%' }}</dd></div>
                </dl>
            </section>
        @endisset
        @isset($s['sync'])
            <section class="card px-5 py-4">
                <h2 class="font-semibold text-navy-950">Offline synchronization</h2>
                <dl class="mt-3 space-y-2 text-sm">
                    <div class="flex justify-between"><dt class="text-slate-600">Active devices</dt><dd class="tabular-nums">{{ $s['sync']['devices']['active'] }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-600">Not synced for 7 days</dt><dd class="tabular-nums">{{ $s['sync']['devices']['not_synced_7_days'] }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-600">Open issues</dt><dd class="tabular-nums">{{ collect($s['sync']['open_issues'])->sum() }}</dd></div>
                </dl>
            </section>
        @endisset
        @isset($s['field_force'])
            <section class="card px-5 py-4">
                <h2 class="font-semibold text-navy-950">Field force</h2>
                <dl class="mt-3 space-y-2 text-sm">
                    <div class="flex justify-between"><dt class="text-slate-600">Visits completed</dt><dd class="tabular-nums">{{ $s['field_force']['visits']['completed'] }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-600">Verified check-ins</dt>
                        <dd class="tabular-nums">{{ $s['field_force']['visits']['verified_percent'] === null ? '—' : $s['field_force']['visits']['verified_percent'].'%' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-600">Orders accepted</dt><dd class="tabular-nums">{{ $s['field_force']['conversion']['orders_accepted'] }}</dd></div>
                </dl>
            </section>
        @endisset
    </div>

    @if ($recommendations->isNotEmpty())
        <section class="card overflow-hidden">
            <header class="border-b border-slate-100 px-5 py-4">
                <h2 class="font-semibold text-navy-950">Recommendations</h2>
                <p class="mt-1 text-xs text-slate-500">Machine-generated. Review before acting; nothing changes until a person accepts one.</p>
            </header>
            <ul class="divide-y divide-slate-100">
                @foreach ($recommendations as $rec)
                    @php $tone = ['critical' => 'bg-rose-100 text-rose-800', 'warning' => 'bg-amber-100 text-amber-800', 'info' => 'bg-slate-100 text-slate-700'][$rec->severity] ?? 'bg-slate-100 text-slate-700'; @endphp
                    <li class="px-5 py-3">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $tone }}">{{ ucfirst($rec->severity) }}</span>
                            <span class="text-xs uppercase tracking-wider text-slate-400">{{ str_replace('_', ' ', $rec->kind) }}</span>
                            <span class="font-medium text-navy-950">{{ $rec->title }}</span>
                        </div>
                        <p class="mt-1 text-sm text-slate-600">{{ $rec->rationale }}</p>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($board['withheld'])
        <div class="rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
            Not shown:
            {{ collect($board['withheld'])->map(fn ($w) => ($labels[$w['section']] ?? $w['section']).($w['reason'] === 'permission' ? ' (needs permission)' : ' (covers all locations)'))->implode(', ') }}.
        </div>
    @endif
</div>

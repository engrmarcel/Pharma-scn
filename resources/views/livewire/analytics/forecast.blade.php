<div class="space-y-6">
    <x-ui.card>
        <div class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_14rem_10rem]">
            <x-ui.field label="Product">@include('livewire.partials.product-picker', ['target' => 'forecast', 'selected' => $productName])</x-ui.field>
            <x-ui.field label="Warehouse" for="warehouse"><select id="warehouse" wire:model.live="warehouse" class="form-select"><option value="">All you can see</option>@foreach ($warehouses as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select></x-ui.field>
            <x-ui.field label="Weeks ahead" for="weeks"><select id="weeks" wire:model.live="weeks" class="form-select">@foreach ([4, 8, 12, 26] as $w)<option value="{{ $w }}">{{ $w }}</option>@endforeach</select></x-ui.field>
        </div>
    </x-ui.card>

    @if ($error)
        <x-ui.card><x-ui.empty icon="exclamation-circle" title="No forecast">{{ $error }}</x-ui.empty></x-ui.card>
    @elseif ($result)
        @php
            $history = collect($result['history'])->take(-26);
            $points = collect($result['forecast']);
            $val = fn ($r) => (float) ($r['value'] ?? $r['quantity'] ?? 0);
            $peak = max(1, (float) $history->max($val), (float) $points->max(fn ($p) => (float) $p['high']));
        @endphp
        <x-ui.card :title="'Forecast · '.$result['unit']" icon="arrow-trending-up">
            <div class="flex h-48 items-end gap-1" aria-label="Weekly demand history and forecast">
                @foreach ($history as $h)
                    <span class="flex-1 rounded-t bg-navy-300" style="height: {{ max(2, $val($h) / $peak * 100) }}%" title="{{ $h['period'] }}: {{ $val($h) }}"></span>
                @endforeach
                @foreach ($points as $p)
                    <span class="flex-1 rounded-t bg-gold-400" style="height: {{ max(2, $val($p) / $peak * 100) }}%" title="{{ $p['week_starting'] }}: {{ $val($p) }}"></span>
                @endforeach
            </div>
            <p class="mt-2 flex gap-4 text-xs text-slate-500"><span><span class="mr-1 inline-block size-2 rounded-sm bg-navy-300"></span>Recorded demand</span><span><span class="mr-1 inline-block size-2 rounded-sm bg-gold-400"></span>Forecast</span></p>
            <div class="table-wrap mt-4 rounded-lg border border-slate-200">
                <table class="data-table">
                    <thead><tr><th>Week starting</th><th class="num">Expected</th><th class="num">Low (80%)</th><th class="num">High (80%)</th></tr></thead>
                    <tbody>@foreach ($points as $p)<tr><td>{{ fmt()->date($p['week_starting']) }}</td><td class="num font-semibold">{{ fmt()->number($val($p), 1) }}</td><td class="num">{{ fmt()->number($p['low'], 1) }}</td><td class="num">{{ fmt()->number($p['high'], 1) }}</td></tr>@endforeach</tbody>
                </table>
            </div>
            <x-ui.dl class="mt-4" :cols="3" :items="['Method' => str_replace('_', ' ', $result['method']), 'Provider' => $result['provider'],
                'Accuracy (backtest)' => is_array($result['accuracy']) ? collect($result['accuracy'])->map(fn ($v, $k) => str_replace('_', ' ', $k).' '.(is_numeric($v) ? round($v, 2) : $v))->implode(' · ') : $result['accuracy']]"/>
            @if (! empty($result['assumptions']))<ul class="mt-3 list-disc pl-5 text-xs text-slate-500">@foreach ((array) $result['assumptions'] as $a)<li>{{ is_array($a) ? json_encode($a) : $a }}</li>@endforeach</ul>@endif
            <p class="mt-2 text-xs text-slate-500">A prediction from your recorded sales; check it against what you know before ordering.</p>
        </x-ui.card>
    @else
        <x-ui.card><x-ui.empty icon="arrow-trending-up" title="Choose a product to forecast"/></x-ui.card>
    @endif
</div>

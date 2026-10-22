<div>
    <x-ui.toolbar>
        <x-ui.field label="From" for="from"><input id="from" type="date" wire:model.live="from" class="form-input w-40"></x-ui.field>
        <x-ui.field label="To" for="to"><input id="to" type="date" wire:model.live="to" class="form-input w-40"></x-ui.field>
        <x-ui.field label="Territory" for="territory"><select id="territory" wire:model.live="territory" class="form-select w-48"><option value="">All</option>@foreach ($territories as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select></x-ui.field>
    </x-ui.toolbar>

    @if (! $data)
        <x-ui.card><x-ui.empty icon="lock-closed" title="CRM analytics needs field force reporting permission"/></x-ui.card>
    @else
        @php
            $scalar = fn ($arr) => collect($arr)->filter(fn ($v) => is_scalar($v) || $v === null);
            $nested = fn ($arr) => collect($arr)->filter(fn ($v) => is_array($v));
        @endphp
        <div class="space-y-6">
            @foreach (['coverage' => 'Coverage', 'visits' => 'Visits', 'conversion' => 'Conversion', 'pipeline' => 'Pipeline', 'detailing' => 'Detailing', 'feedback' => 'Feedback', 'interactions' => 'Interactions'] as $key => $label)
                @continue(! isset($data[$key]) || ! is_array($data[$key]))
                <x-ui.card :title="$label" icon="chart-pie">
                    <div class="grid gap-3 sm:grid-cols-3 lg:grid-cols-5">
                        @foreach ($scalar($data[$key]) as $k => $v)
                            <x-ui.stat :label="str_replace('_', ' ', $k)" :value="$v === null ? '—' : (is_numeric($v) ? (str_contains($k, 'value') ? fmt()->money($v, $currency) : fmt()->number($v, str_contains($k, 'percent') ? 1 : 0).(str_contains($k, 'percent') ? '%' : '')) : str_replace('_', ' ', (string) $v))"/>
                        @endforeach
                    </div>
                    @foreach ($nested($data[$key]) as $k => $group)
                        <div class="mt-4">
                            <p class="mb-1 text-xs font-semibold uppercase text-slate-400">{{ str_replace('_', ' ', $k) }}</p>
                            <div class="flex flex-wrap gap-2 text-xs">
                                @forelse ($group as $gk => $gv)
                                    <span class="rounded-full bg-slate-100 px-3 py-1">{{ is_string($gk) ? str_replace('_', ' ', $gk).': ' : '' }}{{ is_array($gv) ? collect($gv)->map(fn ($x, $xk) => (is_string($xk) ? str_replace('_', ' ', $xk).' ' : '').(is_array($x) ? json_encode($x) : $x))->implode(' · ') : $gv }}</span>
                                @empty
                                    <span class="text-slate-400">none</span>
                                @endforelse
                            </div>
                        </div>
                    @endforeach
                </x-ui.card>
            @endforeach
            @if (! empty($data['segments']))
                <x-ui.card title="Segments" icon="squares-plus">
                    <div class="flex flex-wrap gap-2 text-xs">@foreach ($data['segments'] as $s)<span class="rounded-full bg-slate-100 px-3 py-1">{{ collect($s)->filter(fn ($v) => is_scalar($v))->except(['id', 'segment_id'])->map(fn ($v, $k) => str_replace('_', ' ', $k).': '.$v)->implode(' · ') }}</span>@endforeach</div>
                </x-ui.card>
            @endif
            <p class="text-xs text-slate-500">{{ $data['note'] ?? '' }}</p>
        </div>
    @endif
</div>

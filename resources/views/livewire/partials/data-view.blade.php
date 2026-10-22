{{-- Renders an API report generically: scalars as tiles, lists of records as tables, nested groups as sections. Needs: $data; optional $depth. --}}
@php
    $depth ??= 0;
    $isList = fn ($v) => is_array($v) && array_is_list($v);
    $isRows = fn ($v) => $isList($v) && $v !== [] && collect($v)->every(fn ($r) => is_array($r) && ! array_is_list($r));
    $label = fn ($k) => ucfirst(str_replace(['_', '-'], ' ', (string) $k));
    $show = function ($v, $k = '') {
        if ($v === null || $v === '') return '—';
        if (is_bool($v)) return $v ? 'Yes' : 'No';
        if (is_array($v)) {
            if (isset($v['amount'], $v['currency_code'])) return fmt()->money($v['amount'], $v['currency_code']);
            if (array_is_list($v) && collect($v)->every(fn ($x) => is_array($x) && isset($x['amount'], $x['currency_code']))) {
                return collect($v)->map(fn ($x) => fmt()->money($x['amount'], $x['currency_code']))->implode(' · ') ?: '—';
            }
            return collect($v)->map(fn ($x, $xk) => (is_string($xk) ? str_replace('_', ' ', $xk).': ' : '').(is_array($x) ? json_encode($x, JSON_UNESCAPED_SLASHES) : $x))->implode(' · ');
        }
        if (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}T/', $v)) return fmt()->dateTime($v);
        if (is_numeric($v) && ! preg_match('/(_id|code|number|year)$/', (string) $k)) {
            return fmt()->number($v, str_contains((string) $v, '.') ? 2 : 0).(str_contains((string) $k, 'percent') ? '%' : '');
        }
        return str_replace('_', ' ', (string) $v);
    };
    $scalars = collect($data)->filter(fn ($v, $k) => ! is_array($v) || isset($v['amount'], $v['currency_code']) || ($isList($v) && $v !== [] && collect($v)->every(fn ($x) => is_array($x) && isset($x['amount'], $x['currency_code']))));
    $hidden = ['note', 'notice', 'label', 'disclaimer', 'kind'];
@endphp

@if ($scalars->except($hidden)->isNotEmpty())
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ($scalars->except($hidden) as $k => $v)
            <x-ui.stat :label="$label($k)" :value="$show($v, $k)"/>
        @endforeach
    </div>
@endif

@foreach (collect($data)->except($scalars->keys())->except($hidden) as $k => $v)
    @continue($depth > 2)
    <div class="mt-5">
        <h3 class="mb-2 text-sm font-semibold text-navy-950">{{ $label($k) }}</h3>
        @if ($isRows($v))
            @php $cols = collect($v)->flatMap(fn ($r) => array_keys($r))->unique()->reject(fn ($c) => str_ends_with($c, '_id') && $c !== 'organization_id')->values(); @endphp
            <div class="table-wrap max-h-96 rounded-lg border border-slate-200">
                <table class="data-table">
                    <thead><tr>@foreach ($cols as $c)<th>{{ $label($c) }}</th>@endforeach</tr></thead>
                    <tbody>
                        @foreach (array_slice($v, 0, 200) as $row)
                            <tr>@foreach ($cols as $c)<td class="{{ is_numeric($row[$c] ?? null) ? 'num' : '' }}">{{ $show($row[$c] ?? null, $c) }}</td>@endforeach</tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @elseif (is_array($v) && $v === [])
            <p class="text-sm text-slate-500">None.</p>
        @elseif ($isList($v))
            <p class="text-sm text-slate-700">{{ collect($v)->map(fn ($x) => is_array($x) ? json_encode($x) : str_replace('_', ' ', $x))->implode(', ') }}</p>
        @else
            <div class="rounded-lg border border-slate-200 p-4">@include('livewire.partials.data-view', ['data' => $v, 'depth' => $depth + 1])</div>
        @endif
    </div>
@endforeach

@foreach (['note', 'notice', 'label', 'disclaimer'] as $k)
    @if (isset($data[$k]) && is_string($data[$k]))<p class="mt-3 text-xs text-slate-500">{{ $data[$k] }}</p>@endif
@endforeach

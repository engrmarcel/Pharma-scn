<div class="space-y-6">
    <div class="grid gap-4 sm:grid-cols-3">
        <x-ui.stat label="Plan" :value="$sub['plan']['name'] ?? 'No plan'" :hint="isset($sub['plan']['price']) ? fmt()->money($sub['plan']['price'], $sub['plan']['currency_code'] ?? 'USD').' / '.$sub['plan']['billing_period'] : null"/>
        <x-ui.stat label="Status" :value="ucfirst(str_replace('_', ' ', $sub['status'] ?? 'none'))" :tone="$sub['operational'] ? 'green' : 'red'"
                   :hint="$sub['operational'] ? 'Fully operational' : 'New users, locations and documents cannot be added'"/>
        <x-ui.stat label="Trial ends" :value="$sub['trial_ends_at'] ? fmt()->date($sub['trial_ends_at']) : '—'" tone="gold"/>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <x-ui.card title="Limits and usage" icon="chart-bar">
            <ul class="space-y-4">
                @foreach ($keys as $key => [$kind, $label])
                    @continue($kind !== 'limit')
                    @php
                        $e = $sub['entitlements'][$key] ?? ['enabled' => false, 'limit' => 0];
                        $used = $sub['usage'][$key] ?? null;
                        $limit = $e['limit'];
                        $pct = ($used !== null && $limit) ? min(100, round($used / max(1, $limit) * 100)) : 0;
                    @endphp
                    <li>
                        <div class="flex items-center justify-between text-sm">
                            <span class="text-slate-700">{{ $label }}</span>
                            <span class="tabular-nums text-slate-500">
                                @if (! $e['enabled']) Not included
                                @elseif ($limit === null) {{ $used ?? '—' }} · unlimited
                                @else {{ $used ?? '—' }} of {{ number_format($limit) }} @endif
                            </span>
                        </div>
                        @if ($e['enabled'] && $limit)
                            <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full {{ $pct >= 90 ? 'bg-rose-500' : ($pct >= 70 ? 'bg-amber-500' : 'bg-emerald-500') }}" style="width: {{ $pct }}%"></div></div>
                        @endif
                    </li>
                @endforeach
            </ul>
        </x-ui.card>

        <x-ui.card title="Modules and features" icon="squares-2x2">
            <ul class="grid gap-2 sm:grid-cols-2">
                @foreach ($keys as $key => [$kind, $label])
                    @continue($kind !== 'feature')
                    @php $on = $sub['entitlements'][$key]['enabled'] ?? false; @endphp
                    <li class="flex items-center gap-2 text-sm {{ $on ? 'text-slate-800' : 'text-slate-400' }}">
                        @if ($on)<x-heroicon-s-check-circle class="size-5 text-emerald-500"/>@else<x-heroicon-o-x-circle class="size-5"/>@endif
                        {{ str_replace(' module', '', $label) }}
                    </li>
                @endforeach
            </ul>
            <p class="mt-4 text-xs text-slate-500">To change your plan or add modules, contact platform support.</p>
        </x-ui.card>
    </div>
</div>

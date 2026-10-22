@php
    $t = $board['totals'];
    $cards = [
        ['label' => 'Visits today', 'value' => $t['visits_today'], 'decimals' => 0, 'tone' => 'blue', 'icon' => 'map-pin', 'currency' => null, 'href' => null,
            'hint' => $t['in_progress'].' in progress now'],
        ['label' => 'Planned calls done', 'value' => $t['visited_planned_today'], 'decimals' => 0, 'tone' => 'emerald', 'icon' => 'calendar-days', 'currency' => null, 'href' => null,
            'hint' => 'of '.$t['planned_today'].' planned for the day'],
        ['label' => 'Unverified check-ins', 'value' => $t['unverified_check_ins_today'], 'decimals' => 0, 'tone' => $t['unverified_check_ins_today'] ? 'amber' : 'slate', 'icon' => 'shield-exclamation', 'currency' => null, 'href' => null,
            'hint' => 'No precise fix within the customer radius'],
        ['label' => 'Overdue follow-ups', 'value' => $t['overdue_follow_ups'], 'decimals' => 0, 'tone' => $t['overdue_follow_ups'] ? 'rose' : 'slate', 'icon' => 'clock', 'currency' => null, 'href' => null,
            'hint' => $t['escalated_to_me'].' escalated to you'],
    ];
    $pct = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format($v, 1), '0'), '.').'%';
@endphp

<div class="space-y-8">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h2 class="font-semibold text-navy-950">Supervisor dashboard</h2>
            <p class="mt-1 text-sm text-slate-500">Your team's field work, limited to the customers and territories you are responsible for.</p>
        </div>
        <label class="text-sm text-slate-600">Day
            <input type="date" wire:model.live="date" class="form-field ml-2 px-3 py-2 text-sm" max="{{ today()->toDateString() }}">
        </label>
    </div>

    @if (session('status'))
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('status') }}</div>
    @endif
    @if (session('error'))
        <div class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">{{ session('error') }}</div>
    @endif

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($cards as $i => $card)
            <x-stat-card :card="$card" :delay="$i * 60"/>
        @endforeach
    </div>

    <div class="grid gap-6 xl:grid-cols-[2fr_1fr]">
        <section class="card overflow-hidden">
            <header class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
                <h2 class="font-semibold text-navy-950">Team today</h2>
                <span class="text-xs text-slate-500">Orders and adherence: last 7 days</span>
            </header>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-left text-xs uppercase tracking-wider text-slate-500">
                        <tr>
                            <th class="px-5 py-3">Representative</th><th class="px-3 py-3 text-right">Planned</th><th class="px-3 py-3 text-right">Visits</th>
                            <th class="px-3 py-3">Last check-in</th><th class="px-3 py-3 text-right">Adherence</th><th class="px-3 py-3 text-right">Orders</th>
                            <th class="px-3 py-3 text-right">Accepted value</th><th class="px-5 py-3 text-right">Overdue</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($board['team'] as $rep)
                            <tr wire:key="rep-{{ $rep['representative_id'] }}">
                                <td class="px-5 py-3 font-medium text-navy-900">{{ $rep['name'] }}</td>
                                <td class="px-3 py-3 text-right tabular-nums">{{ $rep['visited_planned_today'] }}/{{ $rep['planned_today'] }}</td>
                                <td class="px-3 py-3 text-right tabular-nums">{{ $rep['visits_today'] }}@if ($rep['in_progress']) <span class="badge ml-1 bg-sky-50 text-sky-700 ring-sky-200">live</span>@endif</td>
                                <td class="px-3 py-3 text-slate-600">
                                    @if ($rep['last_check_in_at'])
                                        {{ \Illuminate\Support\Carbon::parse($rep['last_check_in_at'])->format('H:i') }}
                                        <span class="badge ml-1 {{ $rep['last_check_in_verification'] === 'verified' ? 'bg-emerald-50 text-emerald-700 ring-emerald-200' : 'bg-amber-50 text-amber-700 ring-amber-200' }}">{{ str_replace('_', ' ', $rep['last_check_in_verification']) }}</span>
                                    @else
                                        <span class="text-slate-400">—</span>
                                    @endif
                                </td>
                                <td class="px-3 py-3 text-right tabular-nums">{{ $pct($rep['plan_adherence_7d']) }}</td>
                                <td class="px-3 py-3 text-right tabular-nums">{{ $rep['orders_accepted_7d'] }}/{{ $rep['orders_7d'] }}</td>
                                <td class="px-3 py-3 text-right tabular-nums">{{ number_format((float) $rep['accepted_value_7d'], 2) }}</td>
                                <td class="px-5 py-3 text-right tabular-nums {{ $rep['overdue_follow_ups'] ? 'font-semibold text-rose-600' : '' }}">{{ $rep['overdue_follow_ups'] }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="px-5 py-6 text-center text-slate-500">No team activity for this day.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <div class="space-y-6">
            <section class="card p-5">
                <h2 class="font-semibold text-navy-950">Visit plans awaiting approval</h2>
                <div class="mt-3 space-y-3">
                    @forelse ($board['pending_approvals'] as $plan)
                        <div wire:key="plan-{{ $plan['visit_plan_id'] }}" class="rounded-lg border border-slate-200 p-3 text-sm">
                            <div class="font-medium text-navy-900">{{ $plan['representative'] }}</div>
                            <div class="text-xs text-slate-500">{{ $plan['period_start'] }} → {{ $plan['period_end'] }} · {{ $plan['calls'] }} {{ \Illuminate\Support\Str::plural('customer', $plan['calls']) }}</div>
                            @if ($plan['decidable'])
                                @if ($rejecting === $plan['visit_plan_id'])
                                    <textarea wire:model="notes" rows="2" placeholder="Why is the plan rejected?" class="form-field mt-2 w-full px-3 py-2 text-sm"></textarea>
                                    @error('notes') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
                                    <div class="mt-2 flex gap-2">
                                        <button wire:click="reject" class="rounded border border-rose-300 px-3 py-1.5 text-rose-700">Reject plan</button>
                                        <button wire:click="$set('rejecting', null)" class="form-field px-3 py-1.5">Back</button>
                                    </div>
                                @else
                                    <div class="mt-2 flex gap-2">
                                        <button wire:click="approve('{{ $plan['visit_plan_id'] }}')" class="btn-primary px-3 py-1.5 text-white">Approve</button>
                                        <button wire:click="startReject('{{ $plan['visit_plan_id'] }}')" class="form-field px-3 py-1.5">Reject…</button>
                                    </div>
                                @endif
                            @else
                                <p class="mt-2 text-xs text-amber-700">Includes customers outside your territories: a manager or that territory's supervisor decides it.</p>
                            @endif
                        </div>
                    @empty
                        <p class="text-sm text-slate-500">Nothing waiting.</p>
                    @endforelse
                </div>
            </section>

            <section class="card p-5 text-sm">
                <h2 class="font-semibold text-navy-950">Pipeline</h2>
                <dl class="mt-3 grid grid-cols-2 gap-y-2">
                    <dt class="text-slate-500">Open opportunities</dt><dd class="text-right tabular-nums">{{ $board['pipeline']['open'] }}</dd>
                    <dt class="text-slate-500">Expected value</dt><dd class="text-right tabular-nums">{{ number_format((float) $board['pipeline']['value'], 2) }}</dd>
                    <dt class="text-slate-500">Weighted value</dt><dd class="text-right tabular-nums">{{ number_format((float) $board['pipeline']['weighted_value'], 2) }}</dd>
                    <dt class="text-slate-500">Next steps overdue</dt><dd class="text-right tabular-nums">{{ $board['pipeline']['next_steps_overdue'] }}</dd>
                </dl>
                @if ($board['feedback'])
                    <h2 class="mt-5 font-semibold text-navy-950">Feedback</h2>
                    <dl class="mt-3 grid grid-cols-2 gap-y-2">
                        <dt class="text-slate-500">Open</dt><dd class="text-right tabular-nums">{{ $board['feedback']['open'] }}</dd>
                        <dt class="text-slate-500">Possible adverse events</dt>
                        <dd class="text-right tabular-nums {{ $board['feedback']['possible_adverse_events_open'] ? 'font-semibold text-rose-600' : '' }}">{{ $board['feedback']['possible_adverse_events_open'] }}</dd>
                    </dl>
                @endif
            </section>
        </div>
    </div>

    @if ($board['in_progress']->isNotEmpty())
        <section class="card p-5">
            <h2 class="font-semibold text-navy-950">Visits in progress</h2>
            <ul class="mt-3 divide-y divide-slate-100 text-sm">
                @foreach ($board['in_progress'] as $v)
                    <li wire:key="live-{{ $v['visit_id'] }}" class="flex flex-wrap items-center justify-between gap-2 py-2">
                        <span><span class="font-medium">{{ $v['representative'] }}</span> at {{ $v['customer'] }}</span>
                        <span class="text-xs text-slate-500">since {{ \Illuminate\Support\Carbon::parse($v['checked_in_at'])->format('H:i') }} · {{ str_replace('_', ' ', $v['verification']) }}</span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($board['targets']->isNotEmpty())
        <section class="card p-5">
            <h2 class="font-semibold text-navy-950">Targets this period</h2>
            <div class="mt-4 space-y-4">
                @foreach ($board['targets'] as $target)
                    @php $p = min(100, $target['attainment_percent'] ?? 0); @endphp
                    <div wire:key="target-{{ $target['id'] }}" class="text-sm">
                        <div class="flex flex-wrap justify-between gap-2">
                            <span class="font-medium text-navy-900">{{ str_replace('_', ' ', ucfirst($target['metric'])) }} · {{ $target['scope_type'] }}</span>
                            <span class="tabular-nums text-slate-600">{{ number_format((float) $target['actual'], $target['metric'] === 'visits_completed' ? 0 : 2) }} / {{ number_format((float) $target['target'], $target['metric'] === 'visits_completed' ? 0 : 2) }} ({{ $pct($target['attainment_percent']) }})
                                <span class="badge ml-1 {{ $target['pace'] === 'behind' ? 'bg-amber-50 text-amber-700 ring-amber-200' : 'bg-emerald-50 text-emerald-700 ring-emerald-200' }}">{{ str_replace('_', ' ', $target['pace']) }}</span>
                                @if ($target['scope'] === 'partial')<span class="badge ml-1 bg-slate-50 text-slate-600 ring-slate-200" title="{{ $target['note'] }}">your customers only</span>@endif
                            </span>
                        </div>
                        <div class="mt-1.5 h-2 rounded-full bg-slate-100"><div class="h-2 rounded-full bg-[var(--brand-primary)]" style="width: {{ $p }}%"></div></div>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    @if ($analytics)
        <section class="card p-5">
            <h2 class="font-semibold text-navy-950">CRM analytics · last 30 days</h2>
            <div class="mt-4 grid gap-6 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <dl class="space-y-1">
                    <dt class="text-xs font-semibold uppercase tracking-wider text-slate-500">Coverage</dt>
                    <dd class="text-2xl font-bold text-navy-950">{{ $pct($analytics['coverage']['coverage_percent']) }}</dd>
                    <dd class="text-slate-500">{{ $analytics['coverage']['visited'] }} of {{ $analytics['coverage']['customers'] }} customers visited</dd>
                    <dd class="text-slate-500">Call-frequency compliance {{ $pct($analytics['coverage']['frequency_compliance_percent']) }}</dd>
                </dl>
                <dl class="space-y-1">
                    <dt class="text-xs font-semibold uppercase tracking-wider text-slate-500">Visits</dt>
                    <dd class="text-2xl font-bold text-navy-950">{{ $analytics['visits']['completed'] }}</dd>
                    <dd class="text-slate-500">completed · {{ $pct($analytics['visits']['verified_percent']) }} location-verified</dd>
                </dl>
                <dl class="space-y-1">
                    <dt class="text-xs font-semibold uppercase tracking-wider text-slate-500">Conversion</dt>
                    <dd class="text-2xl font-bold text-navy-950">{{ $pct($analytics['conversion']['visited_customers_ordering_percent']) }}</dd>
                    <dd class="text-slate-500">of visited customers ordered · {{ $analytics['conversion']['orders_accepted'] }} accepted orders</dd>
                </dl>
                <dl class="space-y-1">
                    <dt class="text-xs font-semibold uppercase tracking-wider text-slate-500">Win rate</dt>
                    <dd class="text-2xl font-bold text-navy-950">{{ $pct($analytics['pipeline']['win_rate_percent']) }}</dd>
                    <dd class="text-slate-500">{{ $analytics['pipeline']['won'] }} won · {{ $analytics['pipeline']['lost'] }} lost</dd>
                </dl>
            </div>
            @if ($analytics['detailing'])
                <h3 class="mt-6 text-xs font-semibold uppercase tracking-wider text-slate-500">Detailing reach</h3>
                <ul class="mt-2 divide-y divide-slate-100 text-sm">
                    @foreach ($analytics['detailing'] as $row)
                        <li class="flex flex-wrap justify-between gap-2 py-2"><span>{{ $row['product'] }}</span>
                            <span class="tabular-nums text-slate-600">{{ $row['customers_reached'] }} {{ \Illuminate\Support\Str::plural('customer', $row['customers_reached']) }} · {{ $row['records'] }} {{ \Illuminate\Support\Str::plural('record', $row['records']) }} · {{ $pct($row['positive_percent']) }} positive</span></li>
                    @endforeach
                </ul>
            @endif
        </section>
    @endif
</div>

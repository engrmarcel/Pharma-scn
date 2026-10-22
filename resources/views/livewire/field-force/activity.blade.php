<div>
    <x-ui.toolbar>
        <x-ui.field label="From" for="from"><input id="from" type="date" wire:model.live="from" class="form-input w-44"></x-ui.field>
        <x-ui.field label="To" for="to"><input id="to" type="date" wire:model.live="to" class="form-input w-44"></x-ui.field>
        <x-slot:actions><button type="button" class="btn-light" onclick="window.print()"><x-heroicon-o-printer class="size-4"/>Print</button></x-slot:actions>
    </x-ui.toolbar>

    @if (! $report)
        <x-ui.card><x-ui.empty icon="lock-closed" title="The activity report needs field force reporting permission"/></x-ui.card>
    @else
        <div class="card overflow-hidden">
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Representative</th><th class="num">Visits</th><th class="num">Verified</th><th class="num">Unverified</th><th class="num">Customers</th><th class="num">Avg. minutes</th>
                        <th class="num">Orders</th><th class="num">Accepted</th><th class="num">Accepted value</th><th class="num">Follow-ups on time</th><th class="num">Overdue</th></tr></thead>
                    <tbody>
                        @forelse ($report['representatives'] as $r)
                            <tr>
                                <td class="font-medium text-navy-900">{{ $r['name'] }}</td>
                                <td class="num">{{ $r['visits'] }}</td>
                                <td class="num text-emerald-700">{{ $r['verified_check_ins'] }}</td>
                                <td class="num {{ $r['unverified_check_ins'] + $r['without_location'] ? 'text-amber-700' : '' }}">{{ $r['unverified_check_ins'] + $r['without_location'] }}</td>
                                <td class="num">{{ $r['customers_visited'] }}</td>
                                <td class="num">{{ $r['average_visit_minutes'] ?? '—' }}</td>
                                <td class="num">{{ $r['orders_captured'] }}</td>
                                <td class="num">{{ $r['orders_accepted'] }}</td>
                                <td class="num font-semibold">{{ fmt()->money($r['accepted_value'], $currency) }}</td>
                                <td class="num">{{ $r['follow_up_compliance_percent'] !== null ? $r['follow_up_compliance_percent'].'%' : '—' }}</td>
                                <td class="num {{ $r['follow_ups_overdue'] ? 'text-rose-700' : '' }}">{{ $r['follow_ups_overdue'] }}</td>
                            </tr>
                        @empty
                            <x-ui.empty colspan="11" icon="chart-bar-square" title="No field activity in this period"/>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <p class="mt-3 text-xs text-slate-500">{{ $report['note'] }}</p>
    @endif
</div>

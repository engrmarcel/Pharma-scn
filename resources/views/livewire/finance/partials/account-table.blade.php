{{-- Accounts with debit, credit and (optionally) balance, grouped by type. Needs: $rows, $balanceKey (null hides the column). --}}
<div class="card overflow-hidden">
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>Code</th><th>Account</th><th>Type</th><th class="num">Debit</th><th class="num">Credit</th>@if ($balanceKey)<th class="num">Balance</th>@endif</tr></thead>
            <tbody>
                @forelse (collect($rows)->groupBy('type') as $type => $group)
                    <tr class="bg-slate-50"><td colspan="{{ $balanceKey ? 6 : 5 }}" class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $type }}</td></tr>
                    @foreach ($group as $r)
                        <tr><td class="font-mono text-xs">{{ $r['code'] }}</td><td>{{ $r['name'] }}</td><td class="text-xs text-slate-500">{{ $r['type'] }}</td>
                            <td class="num">{{ fmt()->number($r['debit'], 2) }}</td><td class="num">{{ fmt()->number($r['credit'], 2) }}</td>
                            @if ($balanceKey)<td class="num font-medium">{{ fmt()->number($r[$balanceKey], 2) }}</td>@endif</tr>
                    @endforeach
                @empty
                    <x-ui.empty colspan="6" title="No postings in this period"/>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

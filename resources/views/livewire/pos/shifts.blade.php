<div>
    <x-ui.tabs :current="$status" model="status" :tabs="['open' => 'Open now', 'closed' => 'To review', 'reviewed' => 'Reviewed', '' => 'All']"/>

    <div class="card overflow-hidden">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Shift</th><th>Cashier</th><th>Terminal</th><th>Opened</th><th>Closed</th><th class="num">Expected cash</th><th class="num">Counted</th><th class="num">Variance</th><th>Status</th></tr></thead>
                <tbody>
                    @forelse ($rows as $s)
                        <tr wire:key="s-{{ $s['id'] }}" class="cursor-pointer" wire:click="open('{{ $s['id'] }}')">
                            <td class="font-mono text-xs font-semibold">{{ $s['number'] }}</td>
                            <td class="font-medium text-navy-900">{{ $s['cashier_name'] }}</td>
                            <td>{{ $terminals[$s['pos_terminal_id']] ?? '' }}</td>
                            <td>{{ fmt()->dateTime($s['opened_at']) }}</td>
                            <td>{{ $s['closed_at'] ? fmt()->dateTime($s['closed_at']) : '—' }}</td>
                            <td class="num">{{ $s['expected_cash'] !== null ? fmt()->number($s['expected_cash'], 2) : '—' }}</td>
                            <td class="num">{{ $s['counted_cash'] !== null ? fmt()->number($s['counted_cash'], 2) : '—' }}</td>
                            <td class="num font-semibold {{ (float) $s['variance'] < 0 ? 'text-rose-700' : ((float) $s['variance'] > 0 ? 'text-amber-700' : '') }}">{{ $s['variance'] !== null ? fmt()->number($s['variance'], 2) : '—' }}</td>
                            <td>@if ($s['reviewed_at'])<x-ui.badge status="done">reviewed</x-ui.badge>@else<x-ui.badge :status="$s['status']"/>@endif</td>
                        </tr>
                    @empty
                        <x-ui.empty colspan="9" icon="clock" title="No shifts here"/>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-ui.pager :paginator="$meta"/>
    </div>

    <x-ui.modal wire:model="openId" :title="'Shift '.($detail['number'] ?? '')" size="lg">
        @if ($detail)
            <div class="mb-4 grid gap-4 sm:grid-cols-4">
                <x-ui.stat label="Opening float" :value="fmt()->number($detail['opening_float'], 2)"/>
                <x-ui.stat label="Cash in drawer" :value="fmt()->number($detail['drawer'], 2)" tone="gold"/>
                <x-ui.stat label="Counted" :value="$detail['counted_cash'] !== null ? fmt()->number($detail['counted_cash'], 2) : '—'"/>
                <x-ui.stat label="Variance" :value="$detail['variance'] !== null ? fmt()->number($detail['variance'], 2) : '—'" :tone="(float) ($detail['variance'] ?? 0) == 0 ? 'green' : 'red'"/>
            </div>
            <p class="text-sm text-slate-600">{{ $detailRow['cashier_name'] ?? '' }} · {{ $terminals[$detail['pos_terminal_id']] ?? '' }} · opened {{ fmt()->dateTime($detail['opened_at']) }}@if ($detail['closed_at']), closed {{ fmt()->dateTime($detail['closed_at']) }}@endif</p>
            @if ($detail['closing_notes'])<p class="mt-2 text-sm text-slate-600"><strong>Cashier's note:</strong> {{ $detail['closing_notes'] }}</p>@endif
            @if ($detail['review_notes'])<p class="mt-2 text-sm text-slate-600"><strong>Review:</strong> {{ $detail['review_notes'] }}</p>@endif

            <p class="mb-1 mt-4 text-xs font-semibold uppercase text-slate-400">Sales in this shift</p>
            <ul class="max-h-48 divide-y divide-slate-100 overflow-y-auto rounded-lg border border-slate-200 text-sm">
                @forelse ($sales as $sale)
                    <li class="flex justify-between px-3 py-1.5"><span class="font-mono text-xs">{{ $sale['number'] }} <x-ui.badge :status="$sale['status']" class="ml-1"/></span><span class="tabular-nums">{{ fmt()->money($sale['total'], $sale['currency_code']) }}</span></li>
                @empty
                    <li class="px-3 py-2 text-slate-500">No sales.</li>
                @endforelse
            </ul>

            @if ($detail['status'] === 'open' && $can['cash'])
                <form wire:submit="cashMovement" class="mt-4 flex flex-wrap items-end gap-2 rounded-lg border border-slate-200 bg-slate-50 p-3">
                    <x-ui.field label="Cash in (+) or out (−)" for="cash.amount"><input id="cash.amount" wire:model="cash.amount" inputmode="decimal" class="form-input w-36 py-2" placeholder="-5000"></x-ui.field>
                    <x-ui.field label="Reason" for="cash.reason" class="flex-1"><input id="cash.reason" wire:model="cash.reason" class="form-input py-2" placeholder="Banked mid-day"></x-ui.field>
                    <button class="btn-light">Record</button>
                </form>
                <form wire:submit="close" class="mt-3 flex flex-wrap items-end gap-2 rounded-lg border border-slate-200 bg-slate-50 p-3">
                    <x-ui.field label="Counted cash" for="close.counted_cash"><input id="close.counted_cash" wire:model="countedCash" inputmode="decimal" class="form-input w-36 py-2"></x-ui.field>
                    <x-ui.field label="Note" for="close.notes" class="flex-1"><input id="close.notes" wire:model="notes" class="form-input py-2"></x-ui.field>
                    <button class="btn-danger" wire:confirm="Close this cashier's shift?">Close shift</button>
                </form>
            @endif
            @if ($detail['status'] === 'closed' && ! $detail['reviewed_at'] && $can['review'])
                <form wire:submit="review" class="mt-4 space-y-2">
                    <x-ui.field label="Review notes" for="review.notes"><textarea id="review.notes" wire:model="notes" rows="2" class="form-input" placeholder="Variance explained by…"></textarea></x-ui.field>
                    <div class="flex justify-end"><button class="btn-primary">Mark reviewed</button></div>
                </form>
            @endif
        @endif
    </x-ui.modal>
</div>

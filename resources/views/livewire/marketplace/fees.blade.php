<div>
    <x-ui.toolbar>
        <x-ui.field label="From" for="from"><input id="from" type="date" wire:model.live="from" class="form-input w-40"></x-ui.field>
        <x-ui.field label="To" for="to"><input id="to" type="date" wire:model.live="to" class="form-input w-40"></x-ui.field>
    </x-ui.toolbar>
    @if ($rows === null)
        <x-ui.card><x-ui.empty icon="receipt-percent" title="Fees apply to suppliers">Fees are charged on what you sell through the marketplace.</x-ui.empty></x-ui.card>
    @else
        <div class="mb-6 grid gap-4 sm:grid-cols-3">
            <x-ui.stat label="Fee rate" :value="($meta['rate_percent'] ?? '0').'%'"/>
            <x-ui.stat label="Accrued" :value="fmt()->money($meta['accrued_total'] ?? 0, $currency)" tone="amber"/>
            <x-ui.stat label="Waived" :value="fmt()->money($meta['waived_total'] ?? 0, $currency)" tone="green"/>
        </div>
        <div class="card overflow-hidden">
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Date</th><th class="num">Invoiced</th><th class="num">Rate</th><th class="num">Fee</th><th>Status</th><th>Note</th></tr></thead>
                    <tbody>
                        @forelse ($rows as $f)
                            <tr><td>{{ fmt()->date($f['created_at']) }}</td><td class="num">{{ fmt()->money($f['basis_amount'], $f['currency_code']) }}</td><td class="num">{{ $f['rate_percent'] }}%</td>
                                <td class="num font-semibold">{{ fmt()->money($f['amount'], $f['currency_code']) }}</td><td><x-ui.badge :status="$f['status'] === 'accrued' ? 'pending' : $f['status']">{{ $f['status'] }}</x-ui.badge></td><td class="text-xs text-slate-500">{{ $f['waive_reason'] }}</td></tr>
                        @empty
                            <x-ui.empty colspan="6" icon="receipt-percent" title="No fees in this period"/>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>

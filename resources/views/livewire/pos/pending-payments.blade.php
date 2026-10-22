<div>
    <p class="mb-4 text-sm text-slate-600">Confirm each payment once it appears on the terminal's settlement report. Unconfirmed payments stay out of the cash-up.</p>
    <div class="card overflow-hidden">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Sale</th><th>Taken</th><th>Method</th><th>Approval code</th><th class="num">Amount</th><th></th></tr></thead>
                <tbody>
                    @forelse ($payments as $p)
                        <tr wire:key="pp-{{ $p['id'] }}">
                            <td class="font-mono text-xs font-semibold">{{ $sales[$p['pos_sale_id']] ?? '' }}</td>
                            <td>{{ fmt()->dateTime($p['created_at']) }}</td>
                            <td>{{ str_replace('_', ' ', $p['method']) }}</td>
                            <td class="font-mono text-xs">{{ $p['external_approval_code'] ?? '—' }}</td>
                            <td class="num font-semibold">{{ fmt()->number($p['amount'], 2) }}</td>
                            <td class="text-right"><button type="button" class="btn-primary btn-sm" wire:click="confirm('{{ $p['id'] }}')">Confirm</button></td>
                        </tr>
                    @empty
                        <x-ui.empty colspan="6" icon="check-circle" title="Nothing waiting for confirmation"/>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

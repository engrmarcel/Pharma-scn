<div class="space-y-6">
    @if ($canRequest)
        <x-ui.card title="Request a refund" icon="receipt-refund">
            <form wire:submit="findSale" class="flex flex-wrap items-end gap-2">
                <x-ui.field label="Sale number (from the receipt)" for="saleNumber"><input id="saleNumber" wire:model="saleNumber" class="form-input w-56 font-mono uppercase" placeholder="POS-000123"></x-ui.field>
                <button class="btn-light">Find sale</button>
            </form>

            @if ($request)
                <form wire:submit="sendRequest" class="mt-5 space-y-3">
                    <p class="text-sm text-slate-600">Sale <span class="font-mono">{{ $request['sale']['number'] }}</span> · {{ fmt()->money($request['sale']['total'], $request['sale']['currency_code']) }} · <x-ui.badge :status="$request['sale']['status']"/></p>
                    @error('refund.lines') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
                    @foreach ($request['sale']['lines'] as $l)
                        <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-slate-200 px-3 py-2 text-sm" wire:key="rl-{{ $l['id'] }}">
                            <span>{{ $names[$l['product_id']] ?? '' }} · {{ fmt()->number($l['quantity'], 2) }} {{ $l['package_level_code'] }} · {{ fmt()->number($l['line_total'], 2) }}</span>
                            <span class="flex gap-2">
                                <select wire:model="request.lines.{{ $l['id'] }}.restock_state" class="form-select w-36 py-1.5" aria-label="Returned stock goes to"><option value="quarantined">Quarantine</option><option value="available">Back to stock</option><option value="damaged">Damaged</option></select>
                                <input wire:model="request.lines.{{ $l['id'] }}.quantity" inputmode="decimal" class="form-input w-24 py-1.5 text-right" placeholder="Qty" aria-label="Quantity to refund">
                            </span>
                        </div>
                    @endforeach
                    <div class="grid gap-3 sm:grid-cols-[1fr_12rem]">
                        <x-ui.field label="Reason" for="refund.reason"><input id="refund.reason" wire:model="request.reason" class="form-input"></x-ui.field>
                        <x-ui.field label="Refund by" for="refund.refund_method"><select id="refund.refund_method" wire:model="request.refund_method" class="form-select"><option value="cash">Cash</option><option value="bank_transfer">Bank transfer</option></select></x-ui.field>
                    </div>
                    <div class="flex justify-end gap-2"><button type="button" class="btn-light" wire:click="$set('request', null)">Cancel</button><button class="btn-primary">Request refund</button></div>
                </form>
            @endif
        </x-ui.card>
    @endif

    @if ($canApprove)
        <div>
            <x-ui.tabs :current="$status" model="status" :tabs="['pending_approval' => 'Waiting for approval', 'approved' => 'Approved', 'rejected' => 'Rejected']"/>
            <div class="card overflow-hidden">
                <div class="table-wrap">
                    <table class="data-table">
                        <thead><tr><th>Number</th><th>Requested</th><th>Reason</th><th>Method</th><th class="num">Amount</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                            @forelse ($rows as $r)
                                <tr wire:key="r-{{ $r['id'] }}">
                                    <td class="font-mono text-xs font-semibold">{{ $r['number'] }}</td>
                                    <td>{{ fmt()->dateTime($r['created_at']) }}</td>
                                    <td class="max-w-xs">{{ $r['reason'] }}</td>
                                    <td>{{ str_replace('_', ' ', $r['refund_method']) }}</td>
                                    <td class="num font-semibold">{{ fmt()->number($r['amount'], 2) }}</td>
                                    <td><x-ui.badge :status="$r['status']"/></td>
                                    <td class="whitespace-nowrap text-right">
                                        @if ($r['status'] === 'pending_approval')
                                            @if ($deciding === $r['id'])
                                                <input wire:model="decisionNotes" class="form-input mb-1 w-48 py-1 text-xs" placeholder="Notes (optional)">
                                                <button type="button" class="link text-xs text-rose-700" wire:click="decide('{{ $r['id'] }}', 'reject')">Reject</button>
                                                <button type="button" class="link ml-2 text-xs" wire:click="decide('{{ $r['id'] }}', 'approve')">Approve</button>
                                            @else
                                                <button type="button" class="link text-xs" wire:click="$set('deciding', '{{ $r['id'] }}')">Decide</button>
                                            @endif
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <x-ui.empty colspan="7" icon="receipt-refund" title="No refunds here"/>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <x-ui.pager :paginator="$meta"/>
            </div>
        </div>
    @endif
</div>

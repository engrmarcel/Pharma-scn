<div>
    <x-ui.tabs :current="$tab" :tabs="['disputes' => 'Disputes', 'fees' => 'Fees']"/>

    @if ($tab === 'disputes')
        <x-ui.toolbar><x-ui.field label="Status" for="disputeStatus"><select id="disputeStatus" wire:model.live="disputeStatus" class="form-select w-40">@foreach (['escalated', 'open', 'resolved', 'closed'] as $s)<option value="{{ $s }}">{{ ucfirst($s) }}</option>@endforeach</select></x-ui.field></x-ui.toolbar>
        <div class="card overflow-hidden">
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Dispute</th><th>Buyer</th><th>Supplier</th><th>Problem</th><th>Escalated</th><th>Status</th></tr></thead>
                    <tbody>
                        @forelse ($disputes as $d)
                            <tr wire:key="d-{{ $d['id'] }}" class="cursor-pointer" wire:click="$set('openId', '{{ $d['id'] }}')">
                                <td class="font-mono text-xs font-semibold">{{ $d['number'] }}</td><td>{{ $orgs[$d['buyer_organization_id']] ?? '' }}</td><td>{{ $orgs[$d['supplier_organization_id']] ?? '' }}</td>
                                <td>{{ str_replace('_', ' ', $d['type']) }}</td><td>{{ $d['escalated_at'] ? fmt()->dateTime($d['escalated_at']) : '—' }}</td><td><x-ui.badge :status="$d['status']"/></td>
                            </tr>
                        @empty
                            <x-ui.empty colspan="6" icon="scale" title="No disputes here"/>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <x-ui.pager :paginator="$meta"/>
        </div>
    @else
        <div class="mb-6 grid gap-3 sm:grid-cols-3">
            @foreach (collect($bySupplier)->groupBy('status') as $status => $group)
                <x-ui.stat :label="ucfirst($status)" :value="collect($group)->groupBy('currency_code')->map(fn ($g, $c) => fmt()->money(collect($g)->sum('amount'), $c))->implode(' · ')" :tone="$status === 'waived' ? 'green' : 'amber'"/>
            @endforeach
        </div>
        <div class="card overflow-hidden">
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Date</th><th>Supplier</th><th class="num">Invoiced</th><th class="num">Fee</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                        @forelse ($fees as $f)
                            <tr wire:key="f-{{ $f['id'] }}"><td>{{ fmt()->date($f['created_at']) }}</td><td>{{ $orgs[$f['supplier_organization_id']] ?? '' }}</td><td class="num">{{ fmt()->money($f['basis_amount'], $f['currency_code']) }}</td>
                                <td class="num font-semibold">{{ fmt()->money($f['amount'], $f['currency_code']) }}</td><td><x-ui.badge :status="$f['status'] === 'accrued' ? 'pending' : 'done'">{{ $f['status'] }}</x-ui.badge>@if ($f['waive_reason'])<span class="muted block">{{ $f['waive_reason'] }}</span>@endif</td>
                                <td class="text-right">@if ($f['status'] === 'accrued')<button type="button" class="link text-xs" wire:click="$set('waiving', {id: '{{ $f['id'] }}', reason: ''})">Waive</button>@endif</td></tr>
                        @empty
                            <x-ui.empty colspan="6" icon="receipt-percent" title="No fees"/>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <x-ui.pager :paginator="$feeMeta"/>
        </div>
    @endif

    <x-ui.modal wire:model="openId" :title="'Dispute '.($detail['number'] ?? '')" size="lg">
        @if ($detail)
            <p class="text-sm">{{ $orgs[$detail['buyer_organization_id']] ?? '' }} (buyer) against {{ $orgs[$detail['supplier_organization_id']] ?? '' }} (supplier) · {{ str_replace('_', ' ', $detail['type']) }} · asks for {{ str_replace('_', ' ', $detail['requested_resolution']) }}</p>
            <p class="mt-2 whitespace-pre-line text-sm text-slate-700">{{ $detail['description'] }}</p>
            <div class="mt-4 max-h-72 space-y-2 overflow-y-auto">
                @foreach ($detail['messages'] as $m)
                    <div class="rounded-xl px-3 py-2 text-sm {{ $m['author_side'] === 'platform' ? 'bg-amber-50' : 'bg-slate-100' }}"><p>{{ $m['body'] }}</p><p class="mt-1 text-[11px] opacity-60">{{ $m['author_side'] }} · {{ fmt()->dateTime($m['created_at']) }}</p></div>
                @endforeach
            </div>
            <form wire:submit="addNote" class="mt-4 flex gap-2"><x-ui.field for="note.body" class="flex-1"><input wire:model="note" class="form-input py-2" placeholder="Platform note to both parties…"></x-ui.field><button class="btn-primary">Add note</button></form>
            <p class="mt-2 text-xs text-slate-500">The supplier resolves the dispute; platform staff mediate with notes.</p>
        @endif
    </x-ui.modal>

    <x-ui.modal wire:model="waiving" title="Waive fee" size="sm">
        @if ($waiving)<form wire:submit="waive" id="waive-form"><x-ui.field label="Reason" for="waiving.reason"><textarea wire:model="waiving.reason" rows="2" class="form-input"></textarea></x-ui.field></form>@endif
        <x-slot:footer><button type="button" class="btn-light" @click="open = null">Cancel</button><button type="submit" form="waive-form" class="btn-primary">Waive</button></x-slot:footer>
    </x-ui.modal>
</div>

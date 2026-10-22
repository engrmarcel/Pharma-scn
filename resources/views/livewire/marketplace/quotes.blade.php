<div>
    <x-ui.toolbar>
        <x-ui.field label="Status" for="status"><select id="status" wire:model.live="status" class="form-select w-40"><option value="">All</option>@foreach (['submitted', 'accepted', 'rejected', 'withdrawn', 'expired'] as $s)<option value="{{ $s }}">{{ ucfirst($s) }}</option>@endforeach</select></x-ui.field>
    </x-ui.toolbar>

    <div class="space-y-4">
        @forelse ($rows as $q)
            @php $buyer = $q['buyer_organization_id'] === $me; @endphp
            <div class="card p-5" wire:key="q-{{ $q['id'] }}">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="font-mono text-xs font-semibold text-gold-600">{{ $q['number'] }}</p>
                        <p class="font-semibold text-navy-950">{{ $buyer ? 'From '.($orgs[$q['supplier_organization_id']] ?? '') : 'To '.($orgs[$q['buyer_organization_id']] ?? '') }}</p>
                        <p class="text-xs text-slate-500">Valid until {{ fmt()->date($q['valid_until']) }}{{ $q['rfq_id'] ? ' · answers an RFQ' : ' · unsolicited' }}</p>
                    </div>
                    <div class="text-right"><p class="text-lg font-bold tabular-nums">{{ fmt()->money($q['subtotal'], $q['currency_code']) }}</p><x-ui.badge :status="$q['status']"/></div>
                </div>
                <ul class="mt-3 text-sm text-slate-700">@foreach ($q['lines'] as $l)<li>{{ $names[$l['product_id']] ?? '' }} · {{ fmt()->number($l['quantity'], 2) }} {{ $l['package_level_code'] }} × {{ fmt()->number($l['unit_price'], 2) }}</li>@endforeach</ul>
                @if ($q['decision_notes'])<p class="mt-2 text-sm text-slate-600"><strong>Note:</strong> {{ $q['decision_notes'] }}</p>@endif
                @if ($q['status'] === 'submitted')
                    <div class="mt-4 flex flex-wrap justify-end gap-2">
                        @if ($buyer)
                            @if ($rejecting === $q['id'])
                                <input wire:model="notes" class="form-input w-64 py-1.5" placeholder="Why not">
                                <button type="button" class="btn-danger btn-sm" wire:click="reject">Reject</button>
                            @elseif ($can['manage'])
                                <button type="button" class="btn-light btn-sm" wire:click="$set('rejecting', '{{ $q['id'] }}')">Reject</button>
                            @endif
                            @if ($can['place'])<button type="button" class="btn-primary btn-sm" wire:click="accept('{{ $q['id'] }}')">Accept</button>@endif
                        @elseif ($can['respond'])
                            <button type="button" class="btn-light btn-sm" wire:click="withdraw('{{ $q['id'] }}')" wire:confirm="Withdraw this quote?">Withdraw</button>
                        @endif
                    </div>
                @endif
            </div>
        @empty
            <x-ui.card><x-ui.empty icon="document-duplicate" title="No quotes"/></x-ui.card>
        @endforelse
    </div>
    <div class="mt-4"><x-ui.pager :paginator="$meta"/></div>
</div>

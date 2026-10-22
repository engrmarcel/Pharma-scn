<div>
    <x-ui.tabs :current="$as" model="as" :tabs="['' => 'All', 'buyer' => 'Sent by us', 'supplier' => 'Received']"/>
    <x-ui.toolbar>
        <p class="text-sm text-slate-600">Ask suppliers you trade with for prices, then compare and accept the best offer per line.</p>
        <x-slot:actions>@if ($can['manage'])<button type="button" class="btn-primary" wire:click="create"><x-heroicon-o-plus class="size-4"/>New RFQ</button>@endif</x-slot:actions>
    </x-ui.toolbar>

    <div class="card overflow-hidden">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>RFQ</th><th>Title</th><th>Side</th><th>Answer by</th><th class="num">Lines</th><th>Status</th></tr></thead>
                <tbody>
                    @forelse ($rows as $r)
                        <tr wire:key="r-{{ $r['id'] }}" class="cursor-pointer" wire:click="$set('openId', '{{ $r['id'] }}')">
                            <td class="font-mono text-xs font-semibold">{{ $r['number'] }}</td>
                            <td class="font-medium text-navy-900">{{ $r['title'] }}</td>
                            <td><x-ui.badge :tone="$r['buyer_organization_id'] === $me ? 'blue' : 'gold'">{{ $r['buyer_organization_id'] === $me ? 'sent' : 'received' }}</x-ui.badge></td>
                            <td>{{ fmt()->dateTime($r['response_deadline']) }}</td>
                            <td class="num">{{ count($r['lines'] ?? []) }}</td>
                            <td><x-ui.badge :status="$r['status']"/></td>
                        </tr>
                    @empty
                        <x-ui.empty colspan="6" icon="chat-bubble-left-right" title="No RFQs"/>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-ui.pager :paginator="$meta"/>
    </div>

    <x-ui.modal wire:model="openId" :title="($detail['number'] ?? '').' · '.($detail['title'] ?? '')" size="xl">
        @if ($detail)
            <p class="mb-3 text-sm text-slate-600"><x-ui.badge :status="$detail['status']"/> Answers by {{ fmt()->dateTime($detail['response_deadline']) }}@if ($detail['notes']) · {{ $detail['notes'] }}@endif</p>

            @if ($comparison)
                @foreach ($comparison['lines'] as $line)
                    <div class="mb-3 rounded-lg border border-slate-200">
                        <p class="border-b border-slate-100 bg-slate-50 px-3 py-2 text-sm font-medium">{{ $names[$line['product_id']] ?? '' }} · {{ fmt()->number($line['quantity'], 2) }} {{ $line['package_level_code'] }}</p>
                        <ul class="divide-y divide-slate-100 text-sm">
                            @forelse ($line['offers'] as $offer)
                                <li class="flex flex-wrap items-center justify-between gap-2 px-3 py-2 {{ $loop->first ? 'bg-emerald-50/60' : '' }}">
                                    <span>{{ $offer['supplier']['name'] }} <span class="text-xs text-slate-500">· valid to {{ fmt()->date($offer['valid_until']) }}{{ $offer['lead_time_days'] !== null ? ' · '.$offer['lead_time_days'].' days' : '' }}</span></span>
                                    <span class="flex items-center gap-3"><span class="tabular-nums font-semibold">{{ fmt()->number($offer['unit_price'], 2) }}</span><x-ui.badge :status="$offer['status']"/>
                                        @if ($can['place'] && $offer['status'] === 'submitted')<button type="button" class="link text-xs" wire:click="acceptQuote('{{ $offer['quote_id'] }}')" wire:confirm="Accept quote {{ $offer['quote_number'] }}? It becomes an order.">Accept quote</button>@endif</span>
                                </li>
                            @empty
                                <li class="px-3 py-2 text-slate-500">No offers yet.</li>
                            @endforelse
                        </ul>
                    </div>
                @endforeach
                <p class="text-xs text-slate-500">{{ $comparison['note'] }}</p>
            @else
                <ul class="divide-y divide-slate-100 rounded-lg border border-slate-200 text-sm">
                    @foreach ($detail['lines'] as $l)<li class="flex justify-between px-3 py-2"><span>{{ $names[$l['product_id']] ?? '' }} · {{ $l['package_level_code'] }}</span><span class="tabular-nums">{{ fmt()->number($l['quantity'], 2) }}</span></li>@endforeach
                </ul>
            @endif

            <p class="mb-1 mt-4 text-xs font-semibold uppercase text-slate-400">Invited</p>
            <div class="flex flex-wrap gap-2 text-xs">@foreach ($detail['invitations'] as $i)<span class="rounded-full bg-slate-100 px-3 py-1">{{ $i['supplier']['name'] ?? '' }} · {{ $i['status'] }}{{ $i['decline_reason'] ? ': '.$i['decline_reason'] : '' }}</span>@endforeach</div>

            @if ($quote)
                <form wire:submit="sendQuote" class="mt-5 space-y-3 rounded-lg border border-slate-200 bg-slate-50 p-4">
                    <p class="text-sm font-semibold text-navy-950">Your quote</p>
                    @error('quote.lines') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
                    @foreach ($detail['lines'] as $l)
                        <div class="grid items-center gap-2 text-sm sm:grid-cols-[1fr_6rem_7rem_6rem]">
                            <span>{{ $names[$l['product_id']] ?? '' }} · {{ $l['package_level_code'] }}</span>
                            <input wire:model="quote.lines.{{ $l['id'] }}.quantity" inputmode="decimal" class="form-input py-1.5 text-right" aria-label="Quantity">
                            <input wire:model="quote.lines.{{ $l['id'] }}.unit_price" inputmode="decimal" class="form-input py-1.5 text-right" placeholder="Unit price" aria-label="Unit price">
                            <input wire:model="quote.lines.{{ $l['id'] }}.lead_time_days" type="number" min="0" class="form-input py-1.5 text-right" placeholder="Days" aria-label="Lead time">
                        </div>
                    @endforeach
                    <div class="grid gap-3 sm:grid-cols-2">
                        <x-ui.field label="Valid until" for="quote.valid_until"><input type="date" wire:model="quote.valid_until" class="form-input py-2"></x-ui.field>
                        <x-ui.field label="Notes" for="quote.notes"><input wire:model="quote.notes" class="form-input py-2"></x-ui.field>
                    </div>
                    <div class="flex justify-end gap-2"><button type="button" class="btn-light btn-sm" wire:click="$set('quote', null)">Back</button><button class="btn-primary btn-sm">Send quote</button></div>
                </form>
            @endif
            @if (! $quote && (($detail['your_side'] === 'buyer' && in_array($detail['status'], ['draft', 'open'], true)) || ($detail['your_side'] === 'supplier' && $detail['status'] === 'open')))
                <x-ui.field label="Reason (to cancel or decline)" for="rfq.reason" class="mt-4"><input id="rfq.reason" wire:model="reason" class="form-input py-2"></x-ui.field>
            @endif
        @endif
        <x-slot:footer>
            @if ($detail && ! $quote)
                @if ($detail['your_side'] === 'buyer' && $can['manage'])
                    @if ($detail['status'] === 'draft')<button type="button" class="btn-primary" wire:click="changeStatus('open')">Send to suppliers</button>@endif
                    @if ($detail['status'] === 'open')<button type="button" class="btn-light" wire:click="changeStatus('close')">Close</button>@endif
                    @if (in_array($detail['status'], ['draft', 'open'], true))<button type="button" class="btn-danger" wire:click="changeStatus('cancel')">Cancel RFQ</button>@endif
                @elseif ($detail['your_side'] === 'supplier' && $can['respond'] && $detail['status'] === 'open')
                    <button type="button" class="btn-light" wire:click="decline">Decline</button>
                    <button type="button" class="btn-primary" wire:click="startQuote(@js($detail))">Send a quote</button>
                @endif
            @endif
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal wire:model="doc" title="New request for quotation" size="xl">
        @if ($doc)
            <form wire:submit="save" id="rfq-form" class="space-y-5">
                <div class="grid gap-4 sm:grid-cols-3">
                    <x-ui.field label="Title" for="doc.title"><input id="doc.title" wire:model="doc.title" class="form-input"></x-ui.field>
                    <x-ui.field label="Answers by" for="doc.response_deadline"><input id="doc.response_deadline" type="datetime-local" wire:model="doc.response_deadline" class="form-input"></x-ui.field>
                    <x-ui.field label="Deliver to" for="doc.delivery_warehouse_id"><select id="doc.delivery_warehouse_id" wire:model="doc.delivery_warehouse_id" class="form-select">@foreach ($warehouses as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select></x-ui.field>
                    <x-ui.field label="Notes" for="doc.notes" class="sm:col-span-3"><input id="doc.notes" wire:model="doc.notes" class="form-input"></x-ui.field>
                </div>
                @include('livewire.partials.document-lines', ['form' => 'doc', 'showPrice' => false, 'showTax' => false])
                <x-ui.field label="Invite suppliers" error="doc.supplier_organization_ids">
                    <div class="flex flex-wrap gap-x-5 gap-y-2 rounded-lg border border-slate-200 p-3">
                        @forelse ($suppliers as $id => $n)<label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="doc.suppliers.{{ $id }}" class="form-check">{{ $n }}</label>@empty
                            <p class="text-sm text-slate-500">You have no approved trading partners yet. Request to trade from Discover.</p>@endforelse
                    </div>
                </x-ui.field>
            </form>
        @endif
        <x-slot:footer><button type="button" class="btn-light" @click="open = null">Cancel</button><button type="submit" form="rfq-form" class="btn-primary">Save draft</button></x-slot:footer>
    </x-ui.modal>
</div>

<div>
    <x-ui.tabs :current="$as" model="as" :tabs="['' => 'All', 'buyer' => 'Bought', 'supplier' => 'Sold']"/>
    <x-ui.toolbar>
        <x-ui.field label="Status" for="status">
            <select id="status" wire:model.live="status" class="form-select w-48"><option value="">All</option>
                @foreach (['draft', 'pending_approval', 'submitted', 'accepted', 'partially_shipped', 'shipped', 'partially_received', 'received', 'rejected', 'cancelled'] as $s)<option value="{{ $s }}">{{ ucfirst(str_replace('_', ' ', $s)) }}</option>@endforeach
            </select>
        </x-ui.field>
        <x-slot:actions><a href="{{ route('marketplace.discover') }}" class="btn-primary"><x-heroicon-o-magnifying-glass class="size-4"/>Find products</a></x-slot:actions>
    </x-ui.toolbar>

    <div class="card overflow-hidden">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Order</th><th>Side</th><th>With</th><th>Placed</th><th class="num">Subtotal</th><th>Status</th></tr></thead>
                <tbody>
                    @forelse ($rows as $o)
                        <tr wire:key="mo-{{ $o['id'] }}" class="cursor-pointer" wire:click="open('{{ $o['id'] }}')">
                            <td class="font-mono text-xs font-semibold">{{ $o['number'] }}</td>
                            <td><x-ui.badge :tone="$o['your_side'] === 'buyer' ? 'blue' : 'gold'">{{ $o['your_side'] === 'buyer' ? 'bought' : 'sold' }}</x-ui.badge></td>
                            <td class="font-medium text-navy-900">{{ $o['counterpart']['name'] }}</td>
                            <td>{{ fmt()->dateTime($o['submitted_at'] ?? $o['created_at']) }}</td>
                            <td class="num font-semibold">{{ fmt()->money($o['subtotal'], $o['currency_code']) }}</td>
                            <td><x-ui.badge :status="$o['status']"/></td>
                        </tr>
                    @empty
                        <x-ui.empty colspan="6" icon="shopping-bag" title="No marketplace orders"/>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-ui.pager :paginator="$meta"/>
    </div>

    <x-ui.modal wire:model="openId" :title="'Marketplace order '.($detail['number'] ?? '')" size="xl">
        @if ($detail)
            @php $buyer = $detail['your_side'] === 'buyer'; $s = $detail['status']; @endphp
            <div class="mb-4 flex flex-wrap items-center gap-3 text-sm">
                <x-ui.badge :status="$s"/>
                <span>{{ $buyer ? 'From' : 'For' }} <strong class="text-navy-950">{{ $detail['counterpart']['name'] }}</strong></span>
                <x-ui.badge :status="$detail['counterpart']['verification']['status']" title="{{ $detail['counterpart']['verification']['label'] }}">{{ str_replace('_', ' ', $detail['counterpart']['verification']['status']) }}</x-ui.badge>
                @if ($detail['erp_document'])<span class="text-slate-500">Your {{ $buyer ? 'purchase order' : 'sales order' }}: <span class="font-mono">{{ $detail['erp_document']['number'] }}</span> ({{ $detail['erp_document']['status'] }})</span>@endif
            </div>
            <div class="table-wrap rounded-lg border border-slate-200">
                <table class="data-table">
                    <thead><tr><th>Product</th><th>Pack</th><th class="num">Qty</th><th class="num">Price</th><th class="num">Total</th><th class="num">Shipped</th><th class="num">Received</th></tr></thead>
                    <tbody>
                        @foreach ($detail['lines'] as $l)
                            <tr><td class="font-medium">{{ $l['product_name'] }}</td><td>{{ $l['package_level_code'] }}</td><td class="num">{{ fmt()->number($l['quantity'], 2) }}</td>
                                <td class="num">{{ fmt()->number($l['unit_price'], 2) }}</td><td class="num">{{ fmt()->number($l['line_total'], 2) }}</td>
                                <td class="num">{{ fmt()->number($l['shipped_quantity'], 2) }}</td><td class="num">{{ fmt()->number($l['received_quantity'], 2) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="mt-2 text-xs text-slate-500">{{ $detail['note'] }}</p>
            @foreach (['decision_notes' => 'Decision', 'cancel_reason' => 'Cancelled because', 'notes' => 'Notes'] as $k => $label)
                @if ($detail[$k])<p class="mt-2 text-sm"><strong>{{ $label }}:</strong> {{ $detail[$k] }}</p>@endif
            @endforeach

            <div class="mt-5 grid gap-4 lg:grid-cols-2">
                <div>
                    <p class="mb-1 text-xs font-semibold uppercase text-slate-400">Shipments</p>
                    @forelse ($shipments as $sh)
                        <div class="mb-2 rounded-lg border border-slate-200 p-3 text-sm">
                            <div class="flex items-center justify-between"><span class="font-mono text-xs">{{ $sh['number'] }}</span><x-ui.badge :status="$sh['status']"/></div>
                            <p class="text-xs text-slate-500">{{ fmt()->dateTime($sh['shipped_at']) }}{{ $sh['carrier'] ? ' · '.$sh['carrier'] : '' }}{{ $sh['tracking_reference'] ? ' · '.$sh['tracking_reference'] : '' }}</p>
                            <p class="text-xs text-slate-600">{{ collect($sh['lines'])->map(fn ($l) => fmt()->number($l['quantity'], 2).' '.$l['package_level_code'].' ('.collect($l['batches'] ?? [])->pluck('batch_number')->filter()->implode(', ').')')->implode('; ') }}</p>
                            @if ($buyer && $can['place'] && $sh['status'] === 'shipped')<button type="button" class="btn-primary btn-sm mt-2" wire:click="receive('{{ $sh['id'] }}')" wire:confirm="Receive this shipment in full?">Receive</button>@endif
                        </div>
                    @empty
                        <p class="text-sm text-slate-500">None yet.</p>
                    @endforelse
                </div>
                <div>
                    <p class="mb-1 text-xs font-semibold uppercase text-slate-400">Invoices</p>
                    @forelse ($invoices as $inv)
                        <div class="mb-2 rounded-lg border border-slate-200 p-3 text-sm">
                            <div class="flex items-center justify-between"><span class="font-mono text-xs">{{ $inv['number'] }}</span><span class="font-semibold">{{ fmt()->money($inv['total'], $inv['currency_code']) }}</span></div>
                            <p class="text-xs text-slate-500">{{ $inv['payment_status']['status'] }} · open {{ fmt()->number($inv['payment_status']['open_balance'], 2) }} ({{ $inv['payment_status']['source'] }})</p>
                            @if ($buyer && $can['invoice'] && ! ($inv['buyer_record'] ?? null))<button type="button" class="btn-light btn-sm mt-2" wire:click="recordInvoice('{{ $inv['id'] }}')">Record in payables</button>@endif
                            @if ($buyer && ($inv['buyer_record'] ?? null))<p class="mt-1 text-xs text-emerald-700">Recorded as {{ $inv['buyer_record']['number'] ?? '' }} · {{ $inv['buyer_record']['match_status'] ?? '' }}</p>@endif
                        </div>
                    @empty
                        <p class="text-sm text-slate-500">None yet.</p>
                    @endforelse
                </div>
            </div>
            @if ($history)
                <p class="mb-1 mt-4 text-xs font-semibold uppercase text-slate-400">History</p>
                <ul class="text-xs text-slate-600">@foreach ($history as $h)<li>{{ $h['at'] ? fmt()->dateTime($h['at']) : '' }} · {{ str_replace('_', ' ', $h['event']) }} {{ $h['reference'] ?? '' }}</li>@endforeach</ul>
            @endif

            @if ($action)
                <form wire:submit="apply" class="mt-5 space-y-3 rounded-lg border border-slate-200 bg-slate-50 p-4">
                    @if ($action['type'] === 'accept')
                        <x-ui.field label="Ship from (optional)" for="action.warehouse_id"><select wire:model="action.warehouse_id" class="form-select"><option value="">Listing's warehouse</option>@foreach ($warehouses as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select></x-ui.field>
                    @elseif ($action['type'] === 'ship')
                        <div class="grid gap-3 sm:grid-cols-2">
                            <x-ui.field label="Carrier" for="action.carrier"><input wire:model="action.carrier" class="form-input py-2"></x-ui.field>
                            <x-ui.field label="Tracking reference" for="action.tracking_reference"><input wire:model="action.tracking_reference" class="form-input py-2"></x-ui.field>
                        </div>
                        @error('action.lines') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
                        @foreach ($detail['lines'] as $l)
                            <div class="flex items-center justify-between gap-2 text-sm"><span>{{ $l['product_name'] }} · {{ $l['package_level_code'] }}</span><input wire:model="action.lines.{{ $l['id'] }}" inputmode="decimal" class="form-input w-24 py-1.5 text-right" aria-label="Ship"></div>
                        @endforeach
                    @elseif ($action['type'] === 'dispute')
                        <div class="grid gap-3 sm:grid-cols-2">
                            <x-ui.field label="Problem" for="action.type"><select wire:model="action.dispute_type" class="form-select py-2">@foreach ($disputeTypes as $t)<option value="{{ $t }}">{{ ucfirst(str_replace('_', ' ', $t)) }}</option>@endforeach</select></x-ui.field>
                            <x-ui.field label="You ask for" for="action.requested_resolution"><select wire:model="action.requested_resolution" class="form-select py-2">@foreach ($disputeRequests as $t)<option value="{{ $t }}">{{ ucfirst(str_replace('_', ' ', $t)) }}</option>@endforeach</select></x-ui.field>
                        </div>
                        <x-ui.field label="What happened" for="action.description"><textarea wire:model="action.description" rows="3" class="form-input"></textarea></x-ui.field>
                    @endif
                    @if (in_array($action['type'], ['accept', 'reject', 'cancel', 'disapprove'], true))
                        <x-ui.field :label="$action['type'] === 'accept' ? 'Note (optional)' : 'Reason'" for="action.reason"><textarea wire:model="action.reason" rows="2" class="form-input"></textarea></x-ui.field>
                    @endif
                    <div class="flex justify-end gap-2"><button type="button" class="btn-light btn-sm" wire:click="$set('action', null)">Back</button><button class="btn-primary btn-sm">Confirm</button></div>
                </form>
            @endif
        @endif
        <x-slot:footer>
            @if ($detail && ! $action)
                @if ($buyer)
                    @if ($s === 'draft' && $can['place'])<button type="button" class="btn-primary" wire:click="simple('submit')">Send to supplier</button>@endif
                    @if ($s === 'pending_approval' && $can['approve'])<button type="button" class="btn-danger" wire:click="start('disapprove')">Disapprove</button><button type="button" class="btn-primary" wire:click="simple('approve')">Approve</button>@endif
                    @if (in_array($s, ['draft', 'pending_approval', 'submitted'], true) && $can['place'])<button type="button" class="btn-light" wire:click="start('cancel')">Cancel order</button>@endif
                @else
                    @if ($s === 'submitted' && $can['fulfil'])<button type="button" class="btn-danger" wire:click="start('reject')">Reject</button><button type="button" class="btn-primary" wire:click="start('accept')">Accept</button>@endif
                    @if (in_array($s, ['accepted', 'partially_shipped', 'partially_received'], true) && $can['fulfil'])<button type="button" class="btn-primary" wire:click="startShipment(@js($detail))"><x-heroicon-o-truck class="size-4"/>Ship</button>@endif
                    @if (in_array($s, ['partially_shipped', 'shipped', 'partially_received', 'received'], true) && $can['fulfil'])<button type="button" class="btn-light" wire:click="simple('invoice')">Invoice shipped goods</button>@endif
                    @if ($s === 'accepted' && $can['fulfil'])<button type="button" class="btn-light" wire:click="start('cancel')">Cancel</button>@endif
                @endif
                @if (in_array($s, ['accepted', 'partially_shipped', 'shipped', 'partially_received', 'received'], true) && $can['dispute'])<button type="button" class="btn-light" wire:click="start('dispute')">Raise a dispute</button>@endif
            @endif
        </x-slot:footer>
    </x-ui.modal>
</div>

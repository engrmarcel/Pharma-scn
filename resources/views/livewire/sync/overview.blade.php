<div>
    <x-ui.tabs :current="$tab" :tabs="['issues' => 'Issues to review', 'inbox' => 'Conflicts and rejections', 'reconciliation' => 'Per device']"/>

    @if ($tab === 'issues')
        <x-ui.toolbar>
            <x-ui.field label="Status" for="status"><select id="status" wire:model.live="status" class="form-select w-36"><option value="open">Open</option><option value="resolved">Resolved</option><option value="">All</option></select></x-ui.field>
            <x-ui.field label="Type" for="type"><select id="type" wire:model.live="type" class="form-select w-52"><option value="">All</option>@foreach ($types as $t)<option value="{{ $t }}">{{ ucfirst(str_replace('_', ' ', $t)) }}</option>@endforeach</select></x-ui.field>
        </x-ui.toolbar>
        <div class="card overflow-hidden">
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Raised</th><th>Issue</th><th>Product</th><th class="num">Quantity</th><th>Details</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                        @forelse ($rows as $i)
                            <tr wire:key="i-{{ $i['id'] }}">
                                <td class="whitespace-nowrap">{{ fmt()->dateTime($i['created_at']) }}</td>
                                <td><x-ui.badge :tone="$i['severity'] === 'high' ? 'red' : 'amber'">{{ str_replace('_', ' ', $i['type']) }}</x-ui.badge></td>
                                <td>{{ $names[$i['product_id'] ?? ''] ?? '—' }}</td>
                                <td class="num">{{ $i['quantity'] !== null ? fmt()->number($i['quantity'], 2) : '—' }}</td>
                                <td class="max-w-sm text-xs text-slate-600">{{ is_array($i['details']) ? collect($i['details'])->map(fn ($v, $k) => str_replace('_', ' ', $k).': '.(is_array($v) ? json_encode($v) : $v))->implode(' · ') : $i['details'] }}</td>
                                <td><x-ui.badge :status="$i['status'] === 'open' ? 'pending' : 'resolved'">{{ $i['status'] }}</x-ui.badge>@if ($i['resolution'])<span class="muted block">{{ str_replace('_', ' ', $i['resolution']) }}</span>@endif</td>
                                <td class="text-right">@if ($canResolve && $i['status'] === 'open')<button type="button" class="link text-xs" wire:click="startIssue(@js($i))">Resolve</button>@endif</td>
                            </tr>
                        @empty
                            <x-ui.empty colspan="7" icon="check-circle" title="Nothing to review">Issues appear when offline sales or field records clash with what the server knows (for example stock that was not there).</x-ui.empty>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <x-ui.pager :paginator="$meta"/>
        </div>
    @elseif ($tab === 'inbox')
        <div class="card overflow-hidden">
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Received</th><th>Type</th><th>Device transaction</th><th>Error</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                        @forelse ($rows as $t)
                            <tr wire:key="t-{{ $t['id'] }}">
                                <td class="whitespace-nowrap">{{ fmt()->dateTime($t['received_at']) }}</td>
                                <td>{{ str_replace('_', ' ', $t['type']) }}</td>
                                <td class="font-mono text-xs">{{ $t['client_txn_id'] }}</td>
                                <td class="max-w-sm text-xs text-rose-700">{{ $t['error_message'] ?? '' }}</td>
                                <td><x-ui.badge :status="$t['status'] === 'conflict' ? 'warning' : 'failed'">{{ $t['status'] }}</x-ui.badge></td>
                                <td class="text-right">@if ($canResolve)<button type="button" class="link text-xs" wire:click="startInbox({{ $t['id'] }})">Mark resolved</button>@endif</td>
                            </tr>
                        @empty
                            <x-ui.empty colspan="6" icon="check-circle" title="No unresolved conflicts"/>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <x-ui.pager :paginator="$meta"/>
        </div>
    @else
        <x-ui.toolbar>
            <x-ui.field label="From" for="from"><input id="from" type="date" wire:model.live="from" class="form-input w-40"></x-ui.field>
            <x-ui.field label="To" for="to"><input id="to" type="date" wire:model.live="to" class="form-input w-40"></x-ui.field>
        </x-ui.toolbar>
        <div class="grid gap-4 md:grid-cols-2">
            @forelse ($rows as $d)
                <div class="card p-5">
                    <p class="font-semibold text-navy-950">{{ $d['device']['name'] }} <span class="text-sm font-normal text-slate-500">· {{ $d['device']['user'] }} · {{ $d['device']['role'] }}</span></p>
                    <p class="text-xs text-slate-500">Last sync {{ $d['device']['last_sync_at'] ? fmt()->dateTime($d['device']['last_sync_at']) : 'never' }}</p>
                    @php $tx = $d['transactions'] ?? []; @endphp
                    <div class="mt-3 grid grid-cols-3 gap-2 text-center text-sm">
                        <div class="rounded-lg bg-slate-50 p-2"><p class="font-semibold tabular-nums">{{ $tx['synced'] ?? 0 }}</p><p class="text-xs text-slate-500">synced</p></div>
                        <div class="rounded-lg bg-amber-50 p-2"><p class="font-semibold tabular-nums">{{ ($tx['conflict'] ?? 0) + ($tx['rejected'] ?? 0) }}</p><p class="text-xs text-slate-500">conflicts</p></div>
                        <div class="rounded-lg bg-rose-50 p-2"><p class="font-semibold tabular-nums">{{ $tx['unresolved'] ?? 0 }}</p><p class="text-xs text-slate-500">unresolved</p></div>
                    </div>
                    @if (count($d['issues']))<p class="mt-2 text-xs text-slate-600">Issues: {{ collect($d['issues'])->map(fn ($i) => str_replace('_', ' ', $i['type']).' '.$i['status'].' ×'.$i['n'])->implode(', ') }}</p>@endif
                    @if (count($d['offline_pos_sales']))<p class="text-xs text-slate-600">Offline POS sales: {{ collect($d['offline_pos_sales'])->map(fn ($s) => $s['status'].' ×'.$s['n'])->implode(', ') }}</p>@endif
                </div>
            @empty
                <x-ui.card class="md:col-span-2"><x-ui.empty icon="device-phone-mobile" title="No device activity in this period"/></x-ui.card>
            @endforelse
        </div>
    @endif

    <x-ui.modal wire:model="resolving" title="Resolve" size="md">
        @if ($resolving)
            <form wire:submit="resolve" id="res-form" class="space-y-4">
                @if ($resolving['kind'] === 'issue' && $resolving['type'] === 'stock_shortfall')
                    <x-ui.field label="What happened" for="resolving.action"><select wire:model.live="resolving.action" class="form-select"><option value="acknowledge">Acknowledge (the shortfall stands)</option><option value="record_found_stock">The stock was there: record it as found</option></select></x-ui.field>
                    @if ($resolving['action'] === 'record_found_stock')
                        <div class="grid gap-3 sm:grid-cols-3">
                            <x-ui.field label="Batch" for="resolving.batch_number"><input wire:model="resolving.batch_number" class="form-input font-mono uppercase"></x-ui.field>
                            <x-ui.field label="Expiry" for="resolving.expiry_date"><input type="date" wire:model="resolving.expiry_date" class="form-input"></x-ui.field>
                            <x-ui.field label="Unit cost" for="resolving.unit_cost"><input wire:model="resolving.unit_cost" inputmode="decimal" class="form-input"></x-ui.field>
                        </div>
                    @endif
                @endif
                <x-ui.field label="Notes" for="resolving.notes"><textarea wire:model="resolving.notes" rows="3" class="form-input"></textarea></x-ui.field>
            </form>
        @endif
        <x-slot:footer><button type="button" class="btn-light" @click="open = null">Cancel</button><button type="submit" form="res-form" class="btn-primary">Resolve</button></x-slot:footer>
    </x-ui.modal>
</div>

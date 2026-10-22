<div>
    <x-ui.toolbar>
        <x-ui.field label="Batch number" for="batchNumber"><input id="batchNumber" wire:model.live.debounce.500ms="batchNumber" class="form-input w-48 font-mono uppercase" placeholder="Exact number"></x-ui.field>
        <x-ui.field label="Quality" for="quality">
            <select id="quality" wire:model.live="quality" class="form-select w-40"><option value="">All</option><option value="released">Released</option><option value="quarantined">Quarantined</option><option value="rejected">Rejected</option></select>
        </x-ui.field>
        <x-ui.field label="Expiring before" for="expiringBefore"><input id="expiringBefore" type="date" wire:model.live="expiringBefore" class="form-input w-44"></x-ui.field>
    </x-ui.toolbar>

    <div class="card overflow-hidden">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Batch</th><th>Product</th><th>Manufactured</th><th>Expiry</th><th>Quality</th><th>Recall</th><th>Sale block</th></tr></thead>
                <tbody>
                    @forelse ($rows as $b)
                        <tr wire:key="b-{{ $b['id'] }}" class="cursor-pointer" wire:click="open('{{ $b['id'] }}')">
                            <td class="font-mono text-xs font-semibold">{{ $b['batch_number'] }}</td>
                            <td class="font-medium text-navy-900">{{ $names[$b['product_id']] ?? '' }}</td>
                            <td>{{ $b['manufacturing_date'] ? fmt()->date($b['manufacturing_date']) : '—' }}</td>
                            <td>{{ $b['expiry_date'] ? fmt()->date($b['expiry_date']) : '—' }}</td>
                            <td><x-ui.badge :status="$b['quality_status']"/></td>
                            <td>@if ($b['recall_status'] !== 'none')<x-ui.badge :status="$b['recall_status']"/>@else — @endif</td>
                            <td class="text-xs text-slate-500">{{ $b['sale_block_reason'] ? str_replace('_', ' ', $b['sale_block_reason']) : '—' }}</td>
                        </tr>
                    @empty
                        <x-ui.empty colspan="7" icon="qr-code" title="No batches match"/>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-ui.pager :paginator="$meta"/>
    </div>

    <x-ui.modal wire:model="openId" :title="'Batch '.($detail['batch_number'] ?? '')" size="lg">
        @if ($detail)
            <x-ui.dl :cols="3" :items="array_filter([
                'Product' => $names[$detail['product_id']] ?? '',
                'Expiry' => $detail['expiry_date'] ? fmt()->date($detail['expiry_date']) : null,
                'Manufactured' => $detail['manufacturing_date'] ? fmt()->date($detail['manufacturing_date']) : null,
                'Quality' => str_replace('_', ' ', $detail['quality_status']),
                'Recall' => str_replace('_', ' ', $detail['recall_status']),
                'Unit cost' => $canCost && isset($detail['unit_cost']) ? ($detail['unit_cost'] !== null ? fmt()->money($detail['unit_cost'], $detail['currency_code'] ?? 'USD') : '—') : false,
            ], fn ($v) => $v !== false)"/>
            <h3 class="mb-2 mt-5 text-sm font-semibold text-navy-950">Where it is</h3>
            <ul class="divide-y divide-slate-100 rounded-lg border border-slate-200 text-sm">
                @forelse ($detail['balances'] as $bal)
                    <li class="flex items-center justify-between px-3 py-2"><span>{{ $warehouses[$bal['warehouse_id']] ?? '' }} <x-ui.badge :status="$bal['stock_state']" class="ml-1"/></span><span class="tabular-nums">{{ fmt()->number($bal['quantity'], 2) }} @if ((float) $bal['reserved_quantity'])<span class="muted">({{ fmt()->number($bal['reserved_quantity'], 2) }} reserved)</span>@endif</span></li>
                @empty
                    <li class="px-3 py-3 text-slate-500">No stock in your locations.</li>
                @endforelse
            </ul>

            @if ($canDecide)
                @if ($decision)
                    <form wire:submit="decide" class="mt-5 space-y-3 rounded-lg border border-slate-200 bg-slate-50 p-4">
                        <x-ui.field :label="'Reason to '.$decision['action']" for="decision.reason"><textarea id="decision.reason" wire:model="decision.reason" rows="2" class="form-input"></textarea></x-ui.field>
                        <div class="flex justify-end gap-2"><button type="button" class="btn-light btn-sm" wire:click="$set('decision', null)">Cancel</button><button class="btn-primary btn-sm">Confirm {{ $decision['action'] }}</button></div>
                    </form>
                @else
                    <div class="mt-5 flex flex-wrap gap-2">
                        @if ($detail['quality_status'] !== 'quarantined')<button type="button" class="btn-light btn-sm" wire:click="startDecision('quarantine')">Quarantine</button>@endif
                        @if ($detail['quality_status'] !== 'released')<button type="button" class="btn-light btn-sm" wire:click="startDecision('release')">Release</button>@endif
                        @if ($detail['quality_status'] !== 'rejected')<button type="button" class="btn-danger btn-sm" wire:click="startDecision('reject')">Reject</button>@endif
                    </div>
                @endif
            @endif
        @endif
    </x-ui.modal>
</div>

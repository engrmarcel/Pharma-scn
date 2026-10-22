<div>
    <x-ui.toolbar>
        <x-ui.field label="Status" for="status"><select id="status" wire:model.live="status" class="form-select w-36"><option value="open">Open</option><option value="accepted">Accepted</option><option value="dismissed">Dismissed</option><option value="superseded">Superseded</option></select></x-ui.field>
        <x-ui.field label="Type" for="type"><select id="type" wire:model.live="type" class="form-select w-48"><option value="">All</option>@foreach ($types as $t)<option value="{{ $t }}">{{ ucfirst(str_replace('_', ' ', $t)) }}</option>@endforeach</select></x-ui.field>
        <x-slot:actions><button type="button" class="btn-light" wire:click="generate" wire:loading.attr="disabled"><x-heroicon-o-arrow-path class="size-4"/>Refresh now</button></x-slot:actions>
    </x-ui.toolbar>

    <div class="space-y-4">
        @forelse ($rows as $r)
            <div class="card p-5" wire:key="rec-{{ $r['id'] }}">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <x-ui.badge :tone="['critical' => 'red', 'warning' => 'amber'][$r['severity']] ?? 'blue'">{{ $r['severity'] }}</x-ui.badge>
                            <x-ui.badge tone="slate">{{ str_replace('_', ' ', $r['type']) }}</x-ui.badge>
                            <span class="text-xs text-slate-400">{{ fmt()->dateTime($r['generated_at']) }}</span>
                        </div>
                        <h3 class="mt-1 font-semibold text-navy-950">{{ $r['title'] }}</h3>
                        <p class="text-xs text-slate-500">{{ $names[$r['product_id'] ?? ''] ?? '' }}{{ ($r['warehouse_id'] ?? null) ? ' · '.($warehouses[$r['warehouse_id']] ?? '') : '' }}</p>
                    </div>
                    <x-ui.badge :status="$r['status']"/>
                </div>
                <p class="mt-2 text-sm text-slate-700">{{ $r['rationale'] }}</p>
                @if (! empty($r['figures']))<p class="mt-2 text-xs text-slate-500">{{ collect($r['figures'])->map(fn ($v, $k) => str_replace('_', ' ', $k).': '.(is_array($v) ? json_encode($v) : $v))->implode(' · ') }}</p>@endif
                @if (! empty($r['assumptions']))<p class="mt-1 text-xs text-slate-400">Assumes: {{ collect($r['assumptions'])->map(fn ($v, $k) => is_string($k) ? str_replace('_', ' ', $k).' '.(is_array($v) ? json_encode($v) : $v) : (is_array($v) ? json_encode($v) : $v))->implode('; ') }}</p>@endif
                <p class="mt-2 text-[11px] text-slate-400">{{ $r['label'] }}</p>
                @if ($r['decision'])<p class="mt-2 text-sm text-slate-600">Decided {{ fmt()->dateTime($r['decision']['at']) }}{{ $r['decision']['notes'] ? ': '.$r['decision']['notes'] : '' }}
                    @if ($r['decision']['action_type'] === 'purchase_requisition')<a href="{{ route('procurement.requisitions') }}" class="link ml-1">See the requisition</a>@endif</p>@endif
                @if ($r['status'] === 'open')
                    <div class="mt-4 flex justify-end gap-2"><button type="button" class="btn-light btn-sm" wire:click="start(@js($r), 'dismiss')">Dismiss</button><button type="button" class="btn-primary btn-sm" wire:click="start(@js($r), 'accept')">Accept</button></div>
                @endif
            </div>
        @empty
            <x-ui.card><x-ui.empty icon="light-bulb" title="No recommendations here">They are generated daily from stock, demand and expiry; "Refresh now" runs it immediately.</x-ui.empty></x-ui.card>
        @endforelse
    </div>

    <x-ui.modal wire:model="deciding" :title="($deciding['decision'] ?? '') === 'accept' ? 'Accept: '.($deciding['title'] ?? '') : 'Dismiss: '.($deciding['title'] ?? '')" size="md">
        @if ($deciding)
            <form wire:submit="decide" id="rec-form" class="space-y-4">
                @if ($deciding['decision'] === 'accept')
                    @if ($deciding['type'] === 'reorder')
                        <p class="text-sm text-slate-600">A draft requisition is created; nothing is ordered until someone approves it.</p>
                        <div class="grid gap-3 sm:grid-cols-3">
                            <x-ui.field label="Quantity (base units)" for="deciding.quantity"><input wire:model="deciding.quantity" inputmode="decimal" class="form-input"></x-ui.field>
                            <x-ui.field label="Deliver to" for="deciding.warehouse_id"><select wire:model="deciding.warehouse_id" class="form-select"><option value="">As suggested</option>@foreach ($warehouses as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select></x-ui.field>
                            <x-ui.field label="Needed by" for="deciding.needed_by"><input type="date" wire:model="deciding.needed_by" class="form-input"></x-ui.field>
                        </div>
                    @endif
                    <x-ui.field label="Notes (optional)" for="deciding.notes"><textarea wire:model="deciding.notes" rows="2" class="form-input"></textarea></x-ui.field>
                @else
                    <x-ui.field label="Why dismiss" for="deciding.reason"><textarea wire:model="deciding.reason" rows="2" class="form-input"></textarea></x-ui.field>
                @endif
            </form>
        @endif
        <x-slot:footer><button type="button" class="btn-light" @click="open = null">Cancel</button><button type="submit" form="rec-form" class="btn-primary">Confirm</button></x-slot:footer>
    </x-ui.modal>
</div>

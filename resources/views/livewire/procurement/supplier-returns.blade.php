<div>
    <x-ui.toolbar>
        <p class="text-sm text-slate-600">Posting a return removes the stock and records a credit expected from the supplier.</p>
        <x-slot:actions>
            @if ($canReturn)<button type="button" class="btn-primary" wire:click="create"><x-heroicon-o-plus class="size-4"/>New return</button>@endif
        </x-slot:actions>
    </x-ui.toolbar>

    <div class="card overflow-hidden">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Number</th><th>Supplier</th><th>Warehouse</th><th>Reason</th><th class="num">Lines</th><th>Posted</th></tr></thead>
                <tbody>
                    @forelse ($rows as $r)
                        <tr wire:key="r-{{ $r['id'] }}" class="cursor-pointer" wire:click="$set('viewing', @js($r))">
                            <td class="font-mono text-xs font-semibold">{{ $r['number'] }}</td>
                            <td>{{ $suppliers[$r['supplier_id']] ?? '' }}</td>
                            <td>{{ $warehouses[$r['warehouse_id']] ?? '' }}</td>
                            <td><x-ui.badge tone="blue">{{ str_replace('_', ' ', $r['reason_code']) }}</x-ui.badge></td>
                            <td class="num">{{ count($r['lines'] ?? []) }}</td>
                            <td>{{ $r['posted_at'] ? fmt()->dateTime($r['posted_at']) : '—' }}</td>
                        </tr>
                    @empty
                        <x-ui.empty colspan="6" icon="arrow-uturn-left" title="No supplier returns"/>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-ui.pager :paginator="$meta"/>
    </div>

    <x-ui.modal wire:model="viewing" :title="'Return '.($viewing['number'] ?? '')" size="md">
        @if ($viewing)
            <ul class="divide-y divide-slate-100 rounded-lg border border-slate-200 text-sm">
                @foreach ($viewing['lines'] ?? [] as $l)<li class="flex justify-between px-3 py-2"><span class="font-mono">{{ $batches[$l['batch_id']] ?? '' }} <span class="text-slate-500">· {{ $l['stock_state'] }}</span></span><span class="tabular-nums">{{ fmt()->number($l['base_quantity'], 2) }}</span></li>@endforeach
            </ul>
            @if ($viewing['notes'])<p class="mt-3 text-sm text-slate-600">{{ $viewing['notes'] }}</p>@endif
        @endif
    </x-ui.modal>

    <x-ui.modal wire:model="doc" title="Return stock to a supplier" size="lg">
        @if ($doc)
            <form wire:submit="save" id="ret-form" class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-3">
                    <x-ui.field label="Supplier" for="doc.supplier_id">
                        <select id="doc.supplier_id" wire:model="doc.supplier_id" class="form-select"><option value="">Choose…</option>@foreach ($suppliers as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
                    </x-ui.field>
                    <x-ui.field label="From warehouse" for="doc.warehouse_id">
                        <select id="doc.warehouse_id" wire:model.live="doc.warehouse_id" class="form-select"><option value="">Choose…</option>@foreach ($warehouses as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
                    </x-ui.field>
                    <x-ui.field label="Reason" for="doc.reason_code">
                        <select id="doc.reason_code" wire:model="doc.reason_code" class="form-select">@foreach ($reasons as $r)<option value="{{ $r }}">{{ ucfirst(str_replace('_', ' ', $r)) }}</option>@endforeach</select>
                    </x-ui.field>
                    <x-ui.field label="Notes" for="doc.notes" class="sm:col-span-3"><input id="doc.notes" wire:model="doc.notes" class="form-input"></x-ui.field>
                </div>
                @error('doc.lines') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
                @foreach ($doc['lines'] as $i => $l)
                    <div class="grid items-start gap-2 sm:grid-cols-[1fr_9rem_8rem_auto]" wire:key="rl-{{ $i }}">
                        <x-ui.field :for="'doc.lines.'.$i.'.batch_id'">
                            <select wire:model="doc.lines.{{ $i }}.batch_id" class="form-select py-2" @disabled(! $doc['warehouse_id'])>
                                <option value="">{{ $doc['warehouse_id'] ? 'Choose a batch…' : 'Choose the warehouse first' }}</option>
                                @foreach (collect($balances)->unique('batch_id') as $b)<option value="{{ $b['batch_id'] }}">{{ $names[$b['product_id']] ?? '' }} · {{ $b['batch_number'] }}</option>@endforeach
                            </select>
                        </x-ui.field>
                        <select wire:model="doc.lines.{{ $i }}.stock_state" class="form-select py-2" aria-label="Stock state"><option value="available">Available</option><option value="quarantined">Quarantined</option><option value="damaged">Damaged</option></select>
                        <x-ui.field :for="'doc.lines.'.$i.'.base_quantity'"><input wire:model="doc.lines.{{ $i }}.base_quantity" class="form-input py-2" placeholder="Base units" inputmode="decimal"></x-ui.field>
                        <button type="button" class="p-2 text-slate-400 hover:text-rose-600" wire:click="removeLine({{ $i }})" aria-label="Remove"><x-heroicon-o-trash class="size-4"/></button>
                    </div>
                @endforeach
                <button type="button" class="link text-sm" wire:click="addLine">+ Add line</button>
            </form>
        @endif
        <x-slot:footer>
            <button type="button" class="btn-light" @click="open = null">Cancel</button>
            <button type="submit" form="ret-form" class="btn-primary" wire:loading.attr="disabled">Post return</button>
        </x-slot:footer>
    </x-ui.modal>
</div>

<div>
    <x-ui.toolbar>
        <p class="text-sm text-slate-600">Every adjustment posts to the stock ledger with its reason and is audited.</p>
        <x-slot:actions>
            @if ($canAdjust)<button type="button" class="btn-primary" wire:click="create"><x-heroicon-o-plus class="size-4"/>New adjustment</button>@endif
        </x-slot:actions>
    </x-ui.toolbar>

    <div class="card overflow-hidden">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Number</th><th>Date</th><th>Warehouse</th><th>Reason</th><th class="num">Lines</th><th class="num">Net change</th><th>Notes</th></tr></thead>
                <tbody>
                    @forelse ($rows as $a)
                        <tr wire:key="a-{{ $a['id'] }}" class="cursor-pointer" wire:click="$set('viewing', @js($a))">
                            <td class="font-mono text-xs font-semibold">{{ $a['number'] }}</td>
                            <td>{{ fmt()->dateTime($a['created_at']) }}</td>
                            <td>{{ $warehouses[$a['warehouse_id']] ?? '' }}</td>
                            <td><x-ui.badge tone="blue">{{ str_replace('_', ' ', $a['reason_code']) }}</x-ui.badge></td>
                            <td class="num">{{ count($a['lines'] ?? []) }}</td>
                            @php $net = collect($a['lines'] ?? [])->sum(fn ($l) => (float) $l['quantity_delta']); @endphp
                            <td class="num font-semibold {{ $net < 0 ? 'text-rose-700' : 'text-emerald-700' }}">{{ $net > 0 ? '+' : '' }}{{ fmt()->number($net, 2) }}</td>
                            <td class="max-w-xs truncate text-xs text-slate-500">{{ $a['notes'] ?? '' }}</td>
                        </tr>
                    @empty
                        <x-ui.empty colspan="7" icon="adjustments-horizontal" title="No adjustments yet"/>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-ui.pager :paginator="$meta"/>
    </div>

    <x-ui.modal wire:model="viewing" :title="'Adjustment '.($viewing['number'] ?? '')" size="md">
        @if ($viewing)
            <ul class="divide-y divide-slate-100 rounded-lg border border-slate-200 text-sm">
                @foreach ($viewing['lines'] ?? [] as $l)
                    <li class="flex justify-between px-3 py-2"><span class="font-mono">{{ $batches[$l['batch_id']] ?? $l['batch_id'] }} <span class="text-slate-500">· {{ $l['stock_state'] }}</span></span><span class="tabular-nums font-semibold">{{ (float) $l['quantity_delta'] > 0 ? '+' : '' }}{{ fmt()->number($l['quantity_delta'], 2) }}</span></li>
                @endforeach
            </ul>
            @if ($viewing['notes'] ?? null)<p class="mt-3 text-sm text-slate-600">{{ $viewing['notes'] }}</p>@endif
        @endif
    </x-ui.modal>

    <x-ui.modal wire:model="form" title="New stock adjustment" size="lg">
        @if ($form)
            <form wire:submit="save" id="adj-form" class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.field label="Warehouse" for="form.warehouse_id">
                        <select id="form.warehouse_id" wire:model.live="form.warehouse_id" class="form-select"><option value="">Choose…</option>@foreach ($warehouses as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
                    </x-ui.field>
                    <x-ui.field label="Reason" for="form.reason_code">
                        <select id="form.reason_code" wire:model="form.reason_code" class="form-select">@foreach ($reasons as $r)<option value="{{ $r }}">{{ ucfirst(str_replace('_', ' ', $r)) }}</option>@endforeach</select>
                    </x-ui.field>
                    <x-ui.field label="Notes" for="form.notes" class="sm:col-span-2" hint="Required when the reason is Other."><input id="form.notes" wire:model="form.notes" class="form-input"></x-ui.field>
                </div>
                @error('form.lines') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
                @foreach ($form['lines'] as $i => $l)
                    <div class="grid items-start gap-2 sm:grid-cols-[1fr_9rem_8rem_auto]" wire:key="al-{{ $i }}">
                        <x-ui.field :for="'form.lines.'.$i.'.batch_id'">
                            <select wire:model="form.lines.{{ $i }}.batch_id" class="form-select py-2" @disabled(! $form['warehouse_id'])>
                                <option value="">{{ $form['warehouse_id'] ? 'Choose a batch…' : 'Choose the warehouse first' }}</option>
                                @foreach (collect($balances)->unique('batch_id') as $b)
                                    <option value="{{ $b['batch_id'] }}">{{ $names[$b['product_id']] ?? '' }} · {{ $b['batch_number'] }}{{ $b['expiry_date'] ? ' · exp '.$b['expiry_date'] : '' }}</option>
                                @endforeach
                            </select>
                        </x-ui.field>
                        <select wire:model="form.lines.{{ $i }}.stock_state" class="form-select py-2" aria-label="Stock state"><option value="available">Available</option><option value="quarantined">Quarantined</option><option value="damaged">Damaged</option></select>
                        <x-ui.field :for="'form.lines.'.$i.'.quantity_delta'"><input wire:model="form.lines.{{ $i }}.quantity_delta" class="form-input py-2" placeholder="-5 or +5" inputmode="decimal"></x-ui.field>
                        <button type="button" class="p-2 text-slate-400 hover:text-rose-600" wire:click="removeLine({{ $i }})" aria-label="Remove"><x-heroicon-o-trash class="size-4"/></button>
                    </div>
                @endforeach
                <button type="button" class="link text-sm" wire:click="addLine">+ Add line</button>
                <p class="text-xs text-slate-500">Quantities are in base units: negative removes stock, positive adds it.</p>
            </form>
        @endif
        <x-slot:footer>
            <button type="button" class="btn-light" @click="open = null">Cancel</button>
            <button type="submit" form="adj-form" class="btn-primary" wire:loading.attr="disabled">Post adjustment</button>
        </x-slot:footer>
    </x-ui.modal>
</div>

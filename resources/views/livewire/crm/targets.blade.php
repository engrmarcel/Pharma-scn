<div>
    <x-ui.toolbar>
        <p class="text-sm text-slate-600">Achievement comes from invoices, accepted field orders and completed visits. Pace compares it with the share of the period elapsed.</p>
        <x-slot:actions>@if ($canManage)<button type="button" class="btn-primary" wire:click="create"><x-heroicon-o-flag class="size-4"/>New target</button>@endif</x-slot:actions>
    </x-ui.toolbar>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @forelse ($rows as $t)
            @php $a = $t['achievement']; $pct = min(100, (float) ($a['attainment_percent'] ?? 0)); $money = in_array($t['metric'], ['invoiced_revenue', 'field_order_value'], true); @endphp
            <div class="card p-5" wire:key="t-{{ $t['id'] }}">
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-gold-600">{{ str_replace('_', ' ', $t['metric']) }}</p>
                        <p class="font-semibold text-navy-950">{{ $t['scope_type'] === 'representative' ? ($people[$t['representative_id']] ?? '') : ($territories[$t['territory_id']] ?? '') }}</p>
                        <p class="text-xs text-slate-500">{{ fmt()->date($t['period_start']) }} – {{ fmt()->date($t['period_end']) }}{{ $t['product_id'] ? ' · '.($names[$t['product_id']] ?? '') : '' }}</p>
                    </div>
                    <x-ui.badge :tone="$a['pace'] === 'on_track' ? 'green' : ($a['pace'] === 'behind' ? 'red' : 'slate')">{{ str_replace('_', ' ', $a['pace']) }}</x-ui.badge>
                </div>
                <p class="mt-3 text-2xl font-bold tabular-nums text-navy-950">{{ $money ? fmt()->money($a['actual'], $currency) : fmt()->number($a['actual'], 0) }}
                    <span class="text-sm font-normal text-slate-500">of {{ $money ? fmt()->money($a['target'], $currency) : fmt()->number($a['target'], 0) }}</span></p>
                <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full {{ $a['pace'] === 'behind' ? 'bg-rose-500' : 'bg-emerald-500' }}" style="width: {{ $pct }}%"></div></div>
                <p class="mt-1 text-xs text-slate-500">{{ $a['attainment_percent'] ?? 0 }}% · expected by now {{ $money ? fmt()->money($a['expected_to_date'], $currency) : fmt()->number($a['expected_to_date'], 0) }}</p>
                @if ($canManage)<button type="button" class="link mt-3 text-xs text-rose-700" wire:click="remove('{{ $t['id'] }}')" wire:confirm="Remove this target?">Remove</button>@endif
            </div>
        @empty
            <x-ui.card class="md:col-span-2 xl:col-span-3"><x-ui.empty icon="flag" title="No targets"/></x-ui.card>
        @endforelse
    </div>

    <x-ui.modal wire:model="form" title="New target" size="md">
        @if ($form)
            <form wire:submit="save" id="tgt-form" class="grid gap-4 sm:grid-cols-2">
                <x-ui.field label="For" for="form.scope_type"><select id="form.scope_type" wire:model.live="form.scope_type" class="form-select"><option value="representative">A representative</option><option value="territory">A territory</option></select></x-ui.field>
                @if ($form['scope_type'] === 'representative')
                    <x-ui.field label="Representative" for="form.representative_id"><select id="form.representative_id" wire:model="form.representative_id" class="form-select"><option value="">Choose…</option>@foreach ($people as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select></x-ui.field>
                @else
                    <x-ui.field label="Territory" for="form.territory_id"><select id="form.territory_id" wire:model="form.territory_id" class="form-select"><option value="">Choose…</option>@foreach ($territories as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select></x-ui.field>
                @endif
                <x-ui.field label="Measure" for="form.metric"><select id="form.metric" wire:model.live="form.metric" class="form-select">@foreach ($metrics as $m)<option value="{{ $m }}">{{ ucfirst(str_replace('_', ' ', $m)) }}</option>@endforeach</select></x-ui.field>
                <x-ui.field label="Target" for="form.target_value"><input id="form.target_value" wire:model="form.target_value" inputmode="decimal" class="form-input"></x-ui.field>
                <x-ui.field label="From" for="form.period_start"><input id="form.period_start" type="date" wire:model="form.period_start" class="form-input"></x-ui.field>
                <x-ui.field label="To" for="form.period_end"><input id="form.period_end" type="date" wire:model="form.period_end" class="form-input"></x-ui.field>
                <x-ui.field label="Product (optional)" class="sm:col-span-2">@include('livewire.partials.product-picker', ['target' => 'target', 'selected' => $form['product_name']])</x-ui.field>
            </form>
        @endif
        <x-slot:footer><button type="button" class="btn-light" @click="open = null">Cancel</button><button type="submit" form="tgt-form" class="btn-primary">Set target</button></x-slot:footer>
    </x-ui.modal>
</div>

<div>
    <x-ui.toolbar>
        <x-ui.field label="From" for="from"><input id="from" type="date" wire:model.live="from" class="form-input w-40"></x-ui.field>
        <x-ui.field label="To" for="to"><input id="to" type="date" wire:model.live="to" class="form-input w-40"></x-ui.field>
        <x-ui.field label="Source" for="source">
            <select id="source" wire:model.live="source" class="form-select w-48"><option value="">All sources</option>
                @foreach (['manual', 'sales_invoice', 'credit_note', 'payment', 'goods_receipt', 'supplier_invoice', 'supplier_return', 'stock_receipt'] as $s)<option value="{{ $s }}">{{ str_replace('_', ' ', $s) }}</option>@endforeach
            </select>
        </x-ui.field>
        <x-slot:actions>@if ($canPost)<button type="button" class="btn-primary" wire:click="create"><x-heroicon-o-plus class="size-4"/>Manual entry</button>@endif</x-slot:actions>
    </x-ui.toolbar>

    <div class="card overflow-hidden">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Number</th><th>Date</th><th>Description</th><th>Source</th><th class="num">Total</th></tr></thead>
                <tbody>
                    @forelse ($rows as $e)
                        <tr wire:key="e-{{ $e['id'] }}" class="cursor-pointer" wire:click="$set('openId', '{{ $e['id'] }}')">
                            <td class="font-mono text-xs font-semibold">{{ $e['number'] }}</td><td>{{ fmt()->date($e['entry_date']) }}</td><td>{{ $e['description'] }}</td>
                            <td><x-ui.badge tone="blue">{{ str_replace('_', ' ', $e['source_type'] ?? 'manual') }}</x-ui.badge>@if ($e['reversal_of_id'])<x-ui.badge status="reversed" class="ml-1">reversal</x-ui.badge>@endif</td>
                            <td class="num font-semibold">{{ fmt()->money($e['total'], $e['currency_code']) }}</td>
                        </tr>
                    @empty
                        <x-ui.empty colspan="5" icon="book-open" title="No journal entries"/>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-ui.pager :paginator="$meta"/>
    </div>

    <x-ui.modal wire:model="openId" :title="'Journal entry '.($detail['number'] ?? '')" size="lg">
        @if ($detail)
            <p class="mb-3 text-sm text-slate-600">{{ fmt()->date($detail['entry_date']) }} · {{ $detail['description'] }}</p>
            <div class="table-wrap rounded-lg border border-slate-200">
                <table class="data-table">
                    <thead><tr><th>Account</th><th>Memo</th><th class="num">Debit</th><th class="num">Credit</th></tr></thead>
                    <tbody>
                        @foreach ($detail['lines'] as $l)
                            <tr><td>{{ $accountNames[$l['account_id']] ?? '' }}</td><td class="text-xs text-slate-500">{{ $l['memo'] }}</td>
                                <td class="num">{{ (float) $l['debit'] ? fmt()->number($l['debit'], 2) : '' }}</td><td class="num">{{ (float) $l['credit'] ? fmt()->number($l['credit'], 2) : '' }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($canPost && ! $detail['reversal_of_id'])
                <form wire:submit="reverse" class="mt-4 flex flex-wrap items-end gap-2">
                    <x-ui.field label="Reverse with reason" for="reverse.reason" class="flex-1"><input id="reverse.reason" wire:model="reverseReason" class="form-input py-2" placeholder="Posted to the wrong account"></x-ui.field>
                    <button class="btn-danger">Reverse entry</button>
                </form>
            @endif
        @endif
    </x-ui.modal>

    <x-ui.modal wire:model="entry" title="Manual journal entry" size="xl">
        @if ($entry)
            <form wire:submit="save" id="je-form" class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-[12rem_1fr]">
                    <x-ui.field label="Date" for="entry.entry_date"><input id="entry.entry_date" type="date" wire:model="entry.entry_date" class="form-input"></x-ui.field>
                    <x-ui.field label="Description" for="entry.description"><input id="entry.description" wire:model="entry.description" class="form-input"></x-ui.field>
                </div>
                @error('entry.lines') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
                @foreach ($entry['lines'] as $i => $l)
                    <div class="grid items-start gap-2 md:grid-cols-[minmax(0,1fr)_8rem_8rem_minmax(0,1fr)_auto]" wire:key="jl-{{ $i }}">
                        <x-ui.field :for="'entry.lines.'.$i.'.account_id'"><select wire:model="entry.lines.{{ $i }}.account_id" class="form-select py-2"><option value="">Account…</option>@foreach ($accounts as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach</select></x-ui.field>
                        <x-ui.field :for="'entry.lines.'.$i.'.debit'"><input wire:model.live.debounce.400ms="entry.lines.{{ $i }}.debit" inputmode="decimal" class="form-input py-2" placeholder="Debit"></x-ui.field>
                        <x-ui.field :for="'entry.lines.'.$i.'.credit'"><input wire:model.live.debounce.400ms="entry.lines.{{ $i }}.credit" inputmode="decimal" class="form-input py-2" placeholder="Credit"></x-ui.field>
                        <input wire:model="entry.lines.{{ $i }}.memo" class="form-input py-2" placeholder="Memo">
                        <button type="button" class="p-2 text-slate-400 hover:text-rose-600" wire:click="removeLine({{ $i }})" aria-label="Remove"><x-heroicon-o-trash class="size-4"/></button>
                    </div>
                @endforeach
                @php $dr = collect($entry['lines'])->sum(fn ($l) => (float) $l['debit']); $cr = collect($entry['lines'])->sum(fn ($l) => (float) $l['credit']); @endphp
                <div class="flex items-center justify-between text-sm">
                    <button type="button" class="link" wire:click="addLine">+ Add line</button>
                    <span class="tabular-nums {{ abs($dr - $cr) < 0.00005 && $dr > 0 ? 'text-emerald-700' : 'text-rose-700' }}">Debits {{ fmt()->number($dr, 2) }} · Credits {{ fmt()->number($cr, 2) }}</span>
                </div>
            </form>
        @endif
        <x-slot:footer>
            <button type="button" class="btn-light" @click="open = null">Cancel</button>
            <button type="submit" form="je-form" class="btn-primary" wire:loading.attr="disabled">Post entry</button>
        </x-slot:footer>
    </x-ui.modal>
</div>

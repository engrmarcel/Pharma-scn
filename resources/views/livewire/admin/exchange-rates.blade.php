<div>
    <x-ui.toolbar>
        <p class="text-sm text-slate-600">Used to show platform totals in one reporting currency. Tenant books always stay in their own currency.</p>
        <x-slot:actions><button type="button" class="btn-primary" wire:click="start">Record rate</button></x-slot:actions>
    </x-ui.toolbar>
    <div class="card overflow-hidden">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Date</th><th>Pair</th><th class="num">Rate</th><th>Source</th></tr></thead>
                <tbody>
                    @forelse ($rates as $r)
                        <tr><td>{{ fmt()->date($r['effective_date']) }}</td><td class="font-mono text-xs">1 {{ $r['base_currency'] }} = … {{ $r['quote_currency'] }}</td>
                            <td class="num">{{ rtrim(rtrim((string) $r['rate'], '0'), '.') }}</td><td class="text-xs">{{ $r['source'] }}</td></tr>
                    @empty
                        <x-ui.empty colspan="4" icon="currency-dollar" title="No rates recorded"/>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <x-ui.modal wire:model="rate" title="Record exchange rate" size="md">
        @if ($rate)
            <form wire:submit="save" id="rate-form" class="grid gap-4 sm:grid-cols-2">
                <x-ui.field label="Base currency" for="rate.base_currency"><select wire:model="rate.base_currency" class="form-select">@foreach ($currencies as $c)<option>{{ $c }}</option>@endforeach</select></x-ui.field>
                <x-ui.field label="Quote currency" for="rate.quote_currency"><select wire:model="rate.quote_currency" class="form-select">@foreach ($currencies as $c)<option>{{ $c }}</option>@endforeach</select></x-ui.field>
                <x-ui.field label="Rate" for="rate.rate" hint="Units of quote currency per one base unit"><input wire:model="rate.rate" type="number" step="0.00000001" class="form-input"></x-ui.field>
                <x-ui.field label="Effective date" for="rate.effective_date"><input wire:model="rate.effective_date" type="date" class="form-input"></x-ui.field>
                <div class="sm:col-span-2"><x-ui.field label="Source" for="rate.source" hint="e.g. central bank official rate"><input wire:model="rate.source" class="form-input"></x-ui.field></div>
            </form>
        @endif
        <x-slot:footer><button type="button" class="btn-light" @click="open = null">Cancel</button><button type="submit" form="rate-form" class="btn-primary">Record</button></x-slot:footer>
    </x-ui.modal>
</div>

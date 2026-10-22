<div class="grid gap-6 lg:grid-cols-2">
    <x-ui.card title="Event chain" icon="shield-check">
        <p class="text-sm text-slate-600">Each event carries a hash of the one before it. Checking recomputes the chain: any altered or deleted record breaks it from that point on.</p>
        <button type="button" class="btn-primary mt-4" wire:click="check" wire:loading.attr="disabled"><x-heroicon-o-arrow-path class="size-4"/>Check now</button>
        @if ($result)
            <div class="mt-4 rounded-lg border p-4 text-sm {{ $result['intact'] ? 'border-emerald-200 bg-emerald-50 text-emerald-900' : 'border-rose-200 bg-rose-50 text-rose-900' }}" role="status">
                <p class="font-semibold">{{ $result['intact'] ? 'Intact' : 'Broken' }}</p>
                <p>{{ number_format($result['events']) }} events checked.@unless ($result['intact']) The chain breaks at sequence {{ $result['first_broken_sequence'] }}: report this to platform support.@endunless</p>
            </div>
        @endif
    </x-ui.card>

    <x-ui.card title="EPCIS export" icon="arrow-down-tray">
        <p class="text-sm text-slate-600">Your events as a GS1 EPCIS 2.0 JSON-LD document, for regulators and trading partners.</p>
        <div class="mt-4 flex flex-wrap items-end gap-2">
            <x-ui.field label="From" for="from"><input id="from" type="date" wire:model="from" class="form-input w-40"></x-ui.field>
            <x-ui.field label="To" for="to"><input id="to" type="date" wire:model="to" class="form-input w-40"></x-ui.field>
            <button type="button" class="btn-light" wire:click="export"><x-heroicon-o-arrow-down-tray class="size-4"/>Download</button>
        </div>
    </x-ui.card>
</div>

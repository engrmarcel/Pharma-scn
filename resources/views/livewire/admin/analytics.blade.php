<div>
    <x-ui.toolbar>
        <x-ui.field label="From" for="from"><input id="from" type="date" wire:model.live="from" class="form-input"></x-ui.field>
        <x-ui.field label="To" for="to"><input id="to" type="date" wire:model.live="to" class="form-input"></x-ui.field>
        <x-ui.field label="Reporting currency" for="currency" hint="Converted with recorded exchange rates"><select id="currency" wire:model.live="currency" class="form-select w-40"><option value="">Local currencies</option>@foreach ($currencies as $c)<option>{{ $c }}</option>@endforeach</select></x-ui.field>
    </x-ui.toolbar>
    <div class="card p-5" wire:loading.class="opacity-60">
        @if ($data)
            @include('livewire.partials.data-view', ['data' => $data])
        @else
            <p class="text-sm text-slate-500">No figures for this period.</p>
        @endif
    </div>
</div>

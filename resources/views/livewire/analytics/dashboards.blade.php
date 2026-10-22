<div>
    <x-ui.tabs :current="$board" model="board" :tabs="$boards"/>
    <x-ui.toolbar>
        @unless (in_array($board, ['expiry-risk', 'recalls'], true))
            <x-ui.field label="From" for="from"><input id="from" type="date" wire:model.live="from" class="form-input w-40"></x-ui.field>
            <x-ui.field label="To" for="to"><input id="to" type="date" wire:model.live="to" class="form-input w-40"></x-ui.field>
        @endunless
        @if (in_array($board, ['sales', 'purchases', 'pos', 'inventory', 'expiry-risk'], true))
            <x-ui.field label="Warehouse" for="warehouse"><select id="warehouse" wire:model.live="warehouse" class="form-select w-56"><option value="">All you can see</option>@foreach ($warehouses as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select></x-ui.field>
        @endif
        <x-slot:actions><button type="button" class="btn-light" onclick="window.print()"><x-heroicon-o-printer class="size-4"/>Print</button></x-slot:actions>
    </x-ui.toolbar>

    @if ($data === null)
        <x-ui.card><x-ui.empty icon="lock-closed" title="This dashboard is not available to you">{{ $refused }}</x-ui.empty></x-ui.card>
    @else
        <div class="card p-5" wire:loading.class="opacity-60">@include('livewire.partials.data-view', ['data' => $data])</div>
    @endif
</div>

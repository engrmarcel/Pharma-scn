@props(['placeholder' => 'Search…', 'model' => 'search'])
<label class="relative block w-full sm:w-72">
    <span class="sr-only">{{ $placeholder }}</span>
    <x-heroicon-o-magnifying-glass class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400"/>
    <input type="search" wire:model.live.debounce.400ms="{{ $model }}" placeholder="{{ $placeholder }}" {{ $attributes->merge(['class' => 'form-input py-2 pl-9']) }}>
</label>

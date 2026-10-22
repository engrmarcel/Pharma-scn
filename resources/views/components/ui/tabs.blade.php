@props(['tabs' => [], 'model' => 'tab', 'current' => null])
{{-- In-page tabs bound to a Livewire property. $tabs: value => label; $current: the property's value. --}}
<div {{ $attributes->merge(['class' => 'mb-5 flex gap-1 overflow-x-auto border-b border-slate-200']) }} role="tablist">
    @foreach ($tabs as $value => $label)
        <button type="button" role="tab" wire:click="$set('{{ $model }}', '{{ $value }}')"
                class="section-tab {{ $current === $value ? 'is-active' : '' }}" aria-selected="{{ $current === $value ? 'true' : 'false' }}">{{ $label }}</button>
    @endforeach
</div>

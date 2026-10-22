{{-- Filters on the left, actions on the right. --}}
<div {{ $attributes->merge(['class' => 'mb-4 flex flex-wrap items-end justify-between gap-3']) }}>
    <div class="flex flex-1 flex-wrap items-end gap-2">{{ $slot }}</div>
    @isset($actions)<div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>@endisset
</div>

@props(['icon' => 'inbox', 'title' => 'Nothing here yet', 'colspan' => null])
@if ($colspan)
    <tr><td colspan="{{ $colspan }}" class="px-4 py-8">
@endif
<div {{ $attributes->merge(['class' => 'flex flex-col items-center gap-2 py-6 text-center']) }}>
    <span class="grid size-12 place-items-center rounded-full bg-slate-100 text-slate-400"><x-dynamic-component :component="'heroicon-o-'.$icon" class="size-6"/></span>
    <p class="text-sm font-medium text-slate-700">{{ $title }}</p>
    @if ($slot->isNotEmpty())<p class="max-w-md text-xs text-slate-500">{{ $slot }}</p>@endif
</div>
@if ($colspan)
    </td></tr>
@endif

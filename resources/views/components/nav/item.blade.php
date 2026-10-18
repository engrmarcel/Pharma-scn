@props(['href', 'icon', 'title', 'description' => null, 'active' => false])
<a href="{{ $href }}" role="menuitem" {{ $attributes->merge(['class' => 'group menu-item '.($active ? 'bg-navy-50' : '')]) }}>
    <span class="menu-icon"><x-dynamic-component :component="'heroicon-o-'.$icon" class="size-5"/></span>
    <span>
        <span class="block text-sm font-semibold text-navy-900">{{ $title }}</span>
        @if ($description)
            <span class="mt-0.5 block text-xs leading-snug text-slate-500">{{ $description }}</span>
        @endif
    </span>
</a>

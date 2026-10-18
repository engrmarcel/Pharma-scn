@props(['label' => null, 'icon' => null, 'active' => false, 'width' => 'w-80', 'align' => 'left'])
{{-- Opens on hover (pointer) and on click/Enter (touch, keyboard); closes on Escape or outside click. --}}
<div class="relative" x-data="{ open: false, hover: false }"
     @mouseenter="hover = true; open = true" @mouseleave="hover = false; open = false"
     @click.outside="open = false" @keydown.escape.window="open = false">
    <button type="button" class="nav-link {{ $active ? 'is-active' : '' }}" :aria-expanded="open.toString()" aria-haspopup="true"
            @click="open = hover ? true : ! open">
        @if ($icon)
            <x-dynamic-component :component="'heroicon-o-'.$icon" class="size-4 opacity-70"/>
        @endif
        {{ $label }}
        <x-heroicon-m-chevron-down class="size-4 opacity-60 transition-transform duration-200" ::class="open && 'rotate-180'"/>
    </button>
    <div x-cloak x-show="open"
         x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 translate-y-2" x-transition:enter-end="opacity-100 translate-y-0"
         x-transition:leave="transition ease-in duration-100" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
         class="dropdown-panel {{ $width }} {{ $align === 'right' ? 'left-auto right-0' : '' }}" role="menu">
        <span class="absolute -top-4 inset-x-0 h-4" aria-hidden="true"></span>{{-- hover bridge --}}
        {{ $slot }}
    </div>
</div>

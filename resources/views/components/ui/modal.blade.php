@props(['title' => null, 'size' => 'lg'])
{{-- Bind with wire:model="property": open while the property is truthy; closing sets it to null. --}}
@php
    $width = ['sm' => 'max-w-md', 'md' => 'max-w-xl', 'lg' => 'max-w-3xl', 'xl' => 'max-w-5xl', '2xl' => 'max-w-7xl'][$size] ?? 'max-w-3xl';
    $model = $attributes->wire('model')->value();
@endphp
<div x-data="{ open: $wire.entangle('{{ $model }}').live }" x-show="open" x-cloak
     @keydown.escape.window="open = null" class="fixed inset-0 z-40 overflow-y-auto" role="dialog" aria-modal="true" aria-label="{{ $title }}">
    <div class="fixed inset-0 bg-navy-950/50 backdrop-blur-sm" @click="open = null"></div>
    <div class="relative flex min-h-full items-start justify-center p-3 sm:p-8">
        <div class="relative w-full {{ $width }} rounded-2xl bg-white shadow-2xl animate-fade-up">
            <div class="flex items-start justify-between gap-4 border-b border-slate-100 px-5 py-4 sm:px-6">
                <h2 class="text-lg font-semibold text-navy-950">{{ $title }}</h2>
                <button type="button" class="rounded-md p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" @click="open = null" aria-label="Close">
                    <x-heroicon-o-x-mark class="size-5"/>
                </button>
            </div>
            <div class="px-5 py-5 sm:px-6">{{ $slot }}</div>
            @isset($footer)
                <div class="flex flex-wrap items-center justify-end gap-2 rounded-b-2xl border-t border-slate-100 bg-slate-50 px-5 py-3 sm:px-6">{{ $footer }}</div>
            @endisset
        </div>
    </div>
</div>

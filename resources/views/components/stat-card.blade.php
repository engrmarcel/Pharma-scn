@props(['card', 'delay' => 0])
@php
    $tones = [
        'blue' => '#0b4ea2', 'sky' => '#0284c7', 'emerald' => '#059669', 'amber' => '#d97706',
        'rose' => '#e11d48', 'violet' => '#7c3aed', 'gold' => '#d4951c', 'slate' => '#475569',
    ];
    $display = number_format($card['value'], $card['decimals']);
    $tag = $card['href'] ? 'a' : 'div';
@endphp
<{{ $tag }} @if ($card['href']) href="{{ $card['href'] }}" @endif
    class="stat-card animate-fade-up group block" style="--tone: {{ $tones[$card['tone']] ?? $tones['blue'] }}; animation-delay: {{ $delay }}ms">
    <div class="flex items-start justify-between gap-4">
        <div class="min-w-0">
            <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $card['label'] }}</p>
            <p class="mt-2 flex items-baseline gap-1.5 text-3xl font-bold tracking-tight text-navy-950">
                @if ($card['currency'])
                    <span class="text-sm font-semibold text-slate-400">{{ $card['currency'] }}</span>
                @endif
                <span data-count-to="{{ $card['value'] }}" data-decimals="{{ $card['decimals'] }}" class="tabular-nums">{{ $display }}</span>
            </p>
        </div>
        <span class="stat-icon shrink-0 transition-transform duration-300 group-hover:scale-110 group-hover:rotate-3">
            <x-dynamic-component :component="'heroicon-o-'.$card['icon']" class="size-6"/>
        </span>
    </div>
    <p class="mt-3 flex items-center gap-1.5 text-xs text-slate-500">
        <span class="size-1.5 rounded-full bg-[var(--tone)]"></span>{{ $card['hint'] }}
    </p>
</{{ $tag }}>

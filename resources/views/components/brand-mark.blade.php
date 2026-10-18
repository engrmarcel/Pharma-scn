@props(['brand'])
@php($gid = 'bm-'.\Illuminate\Support\Str::random(6))
@if (! empty($brand['logo_url']))
    <img src="{{ $brand['logo_url'] }}" alt="{{ $brand['display_name'] }}" {{ $attributes->merge(['class' => 'h-10 w-auto']) }}>
@else
    {{-- Default emblem: a hexagon (molecule) carrying a small supply network. --}}
    <svg viewBox="0 0 48 48" aria-hidden="true" {{ $attributes->merge(['class' => 'size-10']) }}>
        <defs>
            <linearGradient id="{{ $gid }}" x1="0" y1="0" x2="1" y2="1">
                <stop offset="0" stop-color="#edc45d"/>
                <stop offset="1" stop-color="#b67614"/>
            </linearGradient>
        </defs>
        <path d="M24 2.5 42.6 13.25v21.5L24 45.5 5.4 34.75v-21.5Z" fill="url(#{{ $gid }})"/>
        <path d="M24 7.5 38.3 15.75v16.5L24 40.5 9.7 32.25v-16.5Z" fill="var(--brand-secondary, #0b1f42)"/>
        <g stroke="#fff" stroke-width="1.8" stroke-linecap="round">
            <path d="M16 18.5 24 24l8-5.5M24 24v9" opacity=".85"/>
        </g>
        <circle cx="16" cy="18.5" r="3" fill="#fff"/>
        <circle cx="32" cy="18.5" r="3" fill="#fff"/>
        <circle cx="24" cy="33" r="3" fill="#edc45d"/>
        <circle cx="24" cy="24" r="3.4" fill="#edc45d" stroke="#fff" stroke-width="1.4"/>
    </svg>
@endif

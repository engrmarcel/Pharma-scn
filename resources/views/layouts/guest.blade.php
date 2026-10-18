<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Sign in') · {{ $brand['display_name'] }}</title>
    @if ($brand['favicon_url'] ?? null)
        <link rel="icon" href="{{ $brand['favicon_url'] }}">
    @endif
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>:root { --brand-primary: {{ $brand['primary_color'] }}; --brand-secondary: {{ $brand['secondary_color'] }}; }</style>
    @livewireStyles
</head>
<body class="min-h-screen bg-white text-slate-800 antialiased">
    <div class="grid min-h-screen lg:grid-cols-[1.15fr_1fr]">
        {{-- Story panel --}}
        <aside class="hero-surface relative hidden overflow-hidden text-white lg:flex lg:flex-col">
            <div class="hex-pattern absolute inset-0" aria-hidden="true"></div>

            {{-- Floating capsules --}}
            <div aria-hidden="true">
                <span class="animate-float absolute right-[12%] top-[14%] h-7 w-16 rotate-[-28deg] rounded-full bg-gradient-to-r from-gold-300 from-50% to-white/90 to-50% opacity-80 shadow-lg"></span>
                <span class="animate-float-slow absolute bottom-[18%] left-[8%] h-5 w-12 rotate-[35deg] rounded-full bg-gradient-to-r from-white/80 from-50% to-sky-300 to-50% opacity-60"></span>
                <span class="animate-float-slow absolute right-[30%] top-[62%] size-4 rounded-full bg-gold-300/70"></span>
                <span class="absolute -right-24 -top-24 size-72 rounded-full border border-white/10"></span>
                <span class="absolute -bottom-32 -left-20 size-96 rounded-full border border-gold-300/15"></span>
            </div>

            <div class="relative flex flex-1 flex-col justify-between p-12 xl:p-16">
                <div class="flex items-center gap-3">
                    <x-brand-mark :brand="$brand" class="size-12"/>
                    <div>
                        <p class="font-display text-xl font-bold">{{ $brand['display_name'] }}</p>
                        <p class="text-[11px] font-medium uppercase tracking-[0.25em] text-gold-300">Pharmaceutical supply chain</p>
                    </div>
                </div>

                <div class="max-w-xl">
                    <p class="eyebrow text-gold-300">One connected network</p>
                    <h2 class="font-display mt-3 text-4xl font-bold leading-tight xl:text-5xl">
                        Every medicine, accounted for <span class="text-gold-300">from manufacturer to patient.</span>
                    </h2>

                    {{-- Animated supply network --}}
                    <svg viewBox="0 0 520 220" class="mt-10 w-full max-w-lg" role="img" aria-label="Manufacturers, distributors, wholesalers, pharmacies and hospitals connected on one network">
                        <g fill="none" stroke="#edc45d" stroke-opacity=".55" stroke-width="1.6" stroke-dasharray="6 6" class="animate-flow">
                            <path d="M60 110 C120 110 130 50 190 50"/>
                            <path d="M60 110 C120 110 130 170 190 170"/>
                            <path d="M190 50 C260 50 270 60 340 60"/>
                            <path d="M190 50 C260 60 280 160 340 165"/>
                            <path d="M190 170 C260 170 280 165 340 165"/>
                            <path d="M340 60 C410 60 420 110 470 110"/>
                            <path d="M340 165 C410 165 420 110 470 110"/>
                        </g>
                        @foreach ([[60, 110, 'building-office-2', 'Manufacturer'], [190, 50, 'truck', 'Distributor'], [190, 170, 'archive-box', 'Wholesaler'], [340, 60, 'building-storefront', 'Pharmacy'], [340, 165, 'heart', 'Hospital'], [470, 110, 'user', 'Patient']] as $i => [$x, $y, $icon, $label])
                            <circle cx="{{ $x }}" cy="{{ $y }}" r="22" fill="#edc45d" fill-opacity=".25" class="animate-pulse-ring" style="transform-box: fill-box; transform-origin: center; animation-delay: {{ $i * 450 }}ms"/>
                            <circle cx="{{ $x }}" cy="{{ $y }}" r="22" fill="#0b1f42" stroke="#edc45d" stroke-width="1.5"/>
                            <x-dynamic-component :component="'heroicon-o-'.$icon" :x="$x - 11" :y="$y - 11" width="22" height="22" class="text-gold-200"/>
                            <text x="{{ $x }}" y="{{ $y + 40 }}" text-anchor="middle" fill="#fff" fill-opacity=".75" font-size="11" font-weight="500">{{ $label }}</text>
                        @endforeach
                    </svg>
                </div>

                <ul class="grid gap-4 text-sm text-white/80 xl:grid-cols-3">
                    <li class="flex items-start gap-3">
                        <span class="grid size-9 shrink-0 place-items-center rounded-lg bg-white/10 ring-1 ring-white/15"><x-heroicon-o-qr-code class="size-5 text-gold-300"/></span>
                        <span>Batch and expiry on every stock movement</span>
                    </li>
                    <li class="flex items-start gap-3">
                        <span class="grid size-9 shrink-0 place-items-center rounded-lg bg-white/10 ring-1 ring-white/15"><x-heroicon-o-shield-check class="size-5 text-gold-300"/></span>
                        <span>Verified trading partners and audit trails</span>
                    </li>
                    <li class="flex items-start gap-3">
                        <span class="grid size-9 shrink-0 place-items-center rounded-lg bg-white/10 ring-1 ring-white/15"><x-heroicon-o-arrows-right-left class="size-5 text-gold-300"/></span>
                        <span>First-expiry-first-out across branches</span>
                    </li>
                </ul>
            </div>
            <div class="relative h-1.5 bg-gradient-to-r from-gold-600 via-gold-300 to-gold-600"></div>
        </aside>

        {{-- Form panel --}}
        <main class="relative flex flex-col bg-slate-50 lg:bg-white">
            <div class="hero-surface relative overflow-hidden px-6 py-8 text-white lg:hidden">
                <div class="hex-pattern absolute inset-0" aria-hidden="true"></div>
                <div class="relative flex items-center gap-3">
                    <x-brand-mark :brand="$brand"/>
                    <div>
                        <p class="font-display text-lg font-bold">{{ $brand['display_name'] }}</p>
                        <p class="text-[10px] font-medium uppercase tracking-[0.25em] text-gold-300">Pharmaceutical supply chain</p>
                    </div>
                </div>
            </div>

            <div class="flex flex-1 items-center justify-center px-6 py-12">
                <div class="animate-fade-up w-full max-w-md">
                    @yield('content')
                </div>
            </div>

            <p class="flex items-center justify-center gap-2 px-6 pb-8 text-xs text-slate-500">
                <x-heroicon-o-lifebuoy class="size-4 text-gold-500"/>
                Need help? <a href="mailto:{{ $brand['support_email'] }}" class="font-medium text-[var(--brand-primary)] hover:underline">{{ $brand['support_email'] }}</a>
            </p>
        </main>
    </div>
    @livewireScripts
</body>
</html>

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $brand['display_name'] }} · {{ $c['hero']['eyebrow'] }}</title>
    <meta name="description" content="{{ \Illuminate\Support\Str::limit($c['hero']['subtitle'], 160) }}">
    @if ($brand['favicon_url'] ?? null)
        <link rel="icon" href="{{ $brand['favicon_url'] }}">
    @endif
    @vite(['resources/css/app.css', 'resources/js/landing.js'])
    <style>:root { --brand-primary: {{ $brand['primary_color'] }}; --brand-secondary: {{ $brand['secondary_color'] }}; --hero-overlay: {{ $c['background']['overlay_opacity'] / 100 }}; }</style>
</head>
<body class="bg-white text-slate-800 antialiased">
@php($lgCols = [1 => 'lg:grid-cols-1', 2 => 'lg:grid-cols-2', 3 => 'lg:grid-cols-3', 4 => 'lg:grid-cols-4', 5 => 'lg:grid-cols-5', 6 => 'lg:grid-cols-6'])
@php($mdCols = [2 => 'md:grid-cols-2', 3 => 'md:grid-cols-3', 4 => 'md:grid-cols-4', 5 => 'md:grid-cols-5', 6 => 'md:grid-cols-6'])

{{-- Navigation --}}
<header data-landing-header class="fixed inset-x-0 top-0 z-50 transition duration-300">
    <nav class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-4 sm:px-6 lg:px-8" aria-label="Main">
        <a href="#top" class="flex items-center gap-3 text-white">
            <x-brand-mark :brand="$brand" class="size-10"/>
            <span class="font-display text-lg font-bold">{{ $brand['display_name'] }}</span>
        </a>
        <div class="hidden items-center gap-1 text-sm font-medium text-white/80 md:flex">
            <a href="#network" class="rounded-md px-3 py-2 transition hover:text-white">Network</a>
            <a href="#journey" class="rounded-md px-3 py-2 transition hover:text-white">Traceability</a>
            <a href="#features" class="rounded-md px-3 py-2 transition hover:text-white">Platform</a>
            <a href="#app" class="rounded-md px-3 py-2 transition hover:text-white">Field app</a>
        </div>
        @auth
            <a href="{{ route('dashboard') }}" class="btn-gold">Open dashboard</a>
        @else
            <a href="{{ route('login') }}" class="btn-gold">Sign in</a>
        @endauth
    </nav>
</header>

<main id="top">
    {{-- Hero: flowing particles or a full background video, with an overlay for the text --}}
    <section class="relative isolate flex min-h-[min(100svh,1000px)] items-center overflow-hidden bg-navy-950 text-white">
        @if ($c['background']['mode'] === 'video' && $c['background']['video_url'])
            <video class="absolute inset-0 -z-20 size-full object-cover" autoplay muted loop playsinline preload="metadata"
                   @if ($c['background']['poster_url']) poster="{{ $c['background']['poster_url'] }}" @endif aria-hidden="true">
                <source src="{{ $c['background']['video_url'] }}">
            </video>
        @else
            <canvas data-particles data-density="{{ $c['background']['particle_density'] }}" class="absolute inset-0 -z-20 size-full" aria-hidden="true"></canvas>
        @endif
        <div class="landing-overlay absolute inset-0 -z-10" aria-hidden="true"></div>

        <div class="relative mx-auto w-full max-w-7xl px-4 pb-16 pt-32 sm:px-6 lg:px-8">
            {{-- Network constellation: the links of the chain around the shared platform (large screens). --}}
            @php($orbit = array_slice($c['network']['nodes'], 0, 6))
            <div class="pointer-events-none absolute right-4 top-28 hidden size-[26rem] xl:block 2xl:right-0" aria-hidden="true">
                <svg viewBox="0 0 100 100" class="absolute inset-0 size-full overflow-visible">
                    <circle cx="50" cy="50" r="38" fill="none" stroke="rgb(237 196 93 / .25)" stroke-width=".3" stroke-dasharray="1.2 1.6" class="origin-center animate-[spin_60s_linear_infinite]"/>
                    @foreach ($orbit as $i => $n)
                        @php($a = deg2rad(-90 + $i * 360 / count($orbit)))
                        <line x1="50" y1="50" x2="{{ round(50 + 38 * cos($a), 2) }}" y2="{{ round(50 + 38 * sin($a), 2) }}" stroke="rgb(125 211 252 / .55)" stroke-width=".35" stroke-dasharray="1.5 1.5" class="animate-flow" style="animation-duration: {{ 2 + $i * .3 }}s"/>
                        @php($b2 = deg2rad(-90 + ($i + 1) * 360 / count($orbit)))
                        <line x1="{{ round(50 + 38 * cos($a), 2) }}" y1="{{ round(50 + 38 * sin($a), 2) }}" x2="{{ round(50 + 38 * cos($b2), 2) }}" y2="{{ round(50 + 38 * sin($b2), 2) }}" stroke="rgb(255 255 255 / .12)" stroke-width=".25"/>
                    @endforeach
                </svg>
                <div class="absolute left-1/2 top-1/2 grid size-28 -translate-x-1/2 -translate-y-1/2 place-items-center rounded-full border border-gold-300/40 bg-navy-950/70 text-center shadow-[0_0_60px_10px] shadow-gold-400/15 backdrop-blur">
                    <span class="absolute inset-0 animate-pulse-ring rounded-full bg-gold-300/15"></span>
                    <div><x-brand-mark :brand="$brand" class="mx-auto size-10"/><p class="mt-1 text-[10px] font-semibold uppercase tracking-widest text-gold-300">Platform</p></div>
                </div>
                @foreach ($orbit as $i => $n)
                    @php($a = deg2rad(-90 + $i * 360 / count($orbit)))
                    <div class="animate-float absolute flex -translate-x-1/2 -translate-y-1/2 flex-col items-center gap-1.5" style="left: {{ round(50 + 38 * cos($a), 2) }}%; top: {{ round(50 + 38 * sin($a), 2) }}%; animation-delay: -{{ $i * 1.1 }}s">
                        <span class="grid size-12 place-items-center rounded-2xl border border-white/15 bg-white/10 text-white shadow-lg backdrop-blur-md">
                            <x-dynamic-component :component="'heroicon-o-'.$n['icon']" class="size-6"/>
                        </span>
                        <span class="whitespace-nowrap rounded-full bg-navy-950/60 px-2 py-0.5 text-[11px] font-medium text-white/85">{{ $n['label'] }}</span>
                    </div>
                @endforeach
            </div>

            <div class="relative max-w-3xl xl:max-w-2xl">
                <p class="eyebrow animate-fade-up text-gold-300">{{ $c['hero']['eyebrow'] }}</p>
                <h1 class="animate-fade-up mt-5 font-display text-4xl font-bold leading-tight sm:text-5xl lg:text-6xl" style="animation-delay: 80ms">
                    {{ $c['hero']['title'] }}
                    @if ($c['hero']['highlight'])
                        <span class="landing-gradient-text block">{{ $c['hero']['highlight'] }}</span>
                    @endif
                </h1>
                <p class="animate-fade-up mt-6 max-w-2xl text-lg leading-relaxed text-white/80" style="animation-delay: 160ms">{{ $c['hero']['subtitle'] }}</p>
                <div class="animate-fade-up mt-9 flex flex-wrap gap-3" style="animation-delay: 240ms">
                    <a href="{{ $c['hero']['primary_url'] }}" class="btn-gold px-6 py-3 text-base">{{ $c['hero']['primary_label'] }}</a>
                    @if ($c['hero']['secondary_label'])
                        <a href="{{ $c['hero']['secondary_url'] ?: '#network' }}" class="btn-ghost-light px-6 py-3 text-base">{{ $c['hero']['secondary_label'] }}</a>
                    @endif
                </div>
            </div>

            <dl class="mt-16 grid grid-cols-2 gap-px overflow-hidden rounded-2xl border border-white/10 bg-white/10 backdrop-blur-md {{ $lgCols[count($c['stats'])] }}">
                @foreach ($c['stats'] as $s)
                    <div class="bg-navy-950/40 px-5 py-6">
                        <dt class="text-xs font-medium uppercase tracking-wider text-white/60">{{ $s['label'] }}</dt>
                        <dd class="mt-2 font-display text-3xl font-bold text-white sm:text-4xl">
                            <span data-count-to="{{ $s['value'] }}" data-decimals="0">{{ number_format((float) $s['value']) }}</span><span class="text-gold-300">{{ $s['suffix'] }}</span>
                        </dd>
                    </div>
                @endforeach
            </dl>
        </div>

        <a href="#network" class="absolute bottom-6 left-1/2 hidden -translate-x-1/2 text-white/60 transition hover:text-white sm:block" aria-label="Scroll to the network">
            <x-heroicon-o-chevron-double-down class="size-6 animate-bounce"/>
        </a>
    </section>

    {{-- The network: supply chain flow --}}
    <section id="network" class="relative overflow-hidden bg-white py-24">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div data-reveal class="mx-auto max-w-3xl text-center">
                <p class="eyebrow">{{ $c['network']['eyebrow'] }}</p>
                <h2 class="mt-3 font-display text-3xl font-bold text-navy-950 sm:text-4xl">{{ $c['network']['title'] }}</h2>
                <p class="mt-4 text-lg text-slate-600">{{ $c['network']['intro'] }}</p>
            </div>

            <div class="landing-chain relative mt-16" role="list" aria-label="Supply chain">
                <div class="landing-chain-line" aria-hidden="true"><span class="landing-packet"></span><span class="landing-packet" style="animation-delay: -2.2s"></span><span class="landing-packet landing-packet-gold" style="animation-delay: -4.4s"></span></div>
                @foreach ($c['network']['nodes'] as $i => $n)
                    <div data-reveal style="transition-delay: {{ $i * 90 }}ms" class="landing-node group" role="listitem">
                        <div class="landing-node-icon">
                            <span class="landing-node-ring" aria-hidden="true"></span>
                            <x-dynamic-component :component="'heroicon-o-'.$n['icon']" class="size-7"/>
                        </div>
                        <div class="landing-node-text">
                            <p class="text-xs font-semibold text-gold-500">0{{ $i + 1 }}</p>
                            <h3 class="font-semibold text-navy-950">{{ $n['label'] }}</h3>
                            <p class="mt-1 text-sm text-slate-600">{{ $n['description'] }}</p>
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Shared services around the platform core --}}
            <div class="mt-20 grid grid-cols-1 items-center gap-6 lg:grid-cols-[1fr_auto_1fr]">
                @php($services = collect($c['network']['services']))
                @foreach ([$services->slice(0, (int) ceil($services->count() / 2)), $services->slice((int) ceil($services->count() / 2))] as $side => $group)
                    <div class="grid gap-4 {{ $side === 1 ? 'lg:order-3' : '' }}">
                        @foreach ($group as $svc)
                            <div data-reveal class="landing-service {{ $side === 0 ? 'lg:text-right' : '' }}">
                                <span class="landing-service-icon {{ $side === 0 ? 'lg:order-2' : '' }}"><x-dynamic-component :component="'heroicon-o-'.$svc['icon']" class="size-6"/></span>
                                <div>
                                    <h3 class="font-semibold text-navy-950">{{ $svc['label'] }}</h3>
                                    <p class="text-sm text-slate-600">{{ $svc['description'] }}</p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                    @if ($side === 0)
                        <div data-reveal class="relative mx-auto grid size-56 place-items-center lg:order-2">
                            <span class="absolute inset-0 animate-[spin_40s_linear_infinite] rounded-full border-2 border-dashed border-navy-200" aria-hidden="true"></span>
                            <span class="absolute inset-6 animate-[spin_28s_linear_infinite_reverse] rounded-full border border-gold-300" aria-hidden="true"></span>
                            <span class="absolute inset-0 animate-pulse-ring rounded-full bg-gold-300/20" aria-hidden="true"></span>
                            <div class="hero-surface relative grid size-36 place-items-center rounded-full text-center text-white shadow-2xl shadow-navy-900/30">
                                <div>
                                    <x-brand-mark :brand="$brand" class="mx-auto size-12"/>
                                    <p class="mt-2 text-xs font-semibold uppercase tracking-widest text-gold-300">Shared<br>platform</p>
                                </div>
                            </div>
                        </div>
                    @endif
                @endforeach
            </div>
        </div>
    </section>

    {{-- Journey of a batch --}}
    <section id="journey" class="hero-surface relative overflow-hidden py-24 text-white">
        <div class="hex-pattern absolute inset-0" aria-hidden="true"></div>
        <div class="relative mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <h2 data-reveal class="mx-auto max-w-3xl text-center font-display text-3xl font-bold sm:text-4xl">{{ $c['journey']['title'] }}</h2>
            <ol class="landing-journey mt-14 grid grid-cols-1 gap-8 {{ $mdCols[count($c['journey']['steps'])] }}">
                @foreach ($c['journey']['steps'] as $i => $step)
                    <li data-reveal style="transition-delay: {{ $i * 110 }}ms" class="relative text-center">
                        <span class="landing-step mx-auto">{{ $i + 1 }}</span>
                        <h3 class="mt-4 text-lg font-semibold text-gold-300">{{ $step['title'] }}</h3>
                        <p class="mx-auto mt-2 max-w-xs text-sm text-white/75">{{ $step['description'] }}</p>
                    </li>
                @endforeach
            </ol>
        </div>
    </section>

    {{-- Platform features --}}
    <section id="features" class="bg-slate-50 py-24">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div data-reveal class="mx-auto max-w-3xl text-center">
                <h2 class="font-display text-3xl font-bold text-navy-950 sm:text-4xl">{{ $c['features']['title'] }}</h2>
                <p class="mt-4 text-lg text-slate-600">{{ $c['features']['intro'] }}</p>
            </div>
            <div class="mt-14 grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($c['features']['items'] as $i => $f)
                    <article data-reveal style="transition-delay: {{ ($i % 3) * 90 }}ms" class="group rounded-2xl border border-slate-200 bg-white p-6 shadow-sm transition duration-300 hover:-translate-y-1 hover:shadow-xl hover:shadow-navy-900/10">
                        <span class="grid size-12 place-items-center rounded-xl bg-navy-50 text-[var(--brand-primary)] ring-1 ring-navy-100 transition group-hover:bg-[var(--brand-primary)] group-hover:text-white">
                            <x-dynamic-component :component="'heroicon-o-'.$f['icon']" class="size-6"/>
                        </span>
                        <h3 class="mt-5 text-lg font-semibold text-navy-950">{{ $f['title'] }}</h3>
                        <p class="mt-2 text-sm leading-relaxed text-slate-600">{{ $f['description'] }}</p>
                    </article>
                @endforeach
            </div>
        </div>
    </section>

    {{-- Field force app: text beside the phone screens --}}
    <section id="app" class="relative overflow-hidden bg-white py-24">
        <div class="mx-auto grid max-w-7xl grid-cols-1 items-center gap-16 px-4 sm:px-6 lg:grid-cols-2 lg:px-8">
            <div data-reveal>
                <p class="eyebrow">{{ $c['app']['eyebrow'] }}</p>
                <h2 class="mt-3 font-display text-3xl font-bold text-navy-950 sm:text-4xl">{{ $c['app']['title'] }}</h2>
                <p class="mt-5 text-lg leading-relaxed text-slate-600">{{ $c['app']['description'] }}</p>
                <ul class="mt-8 space-y-4">
                    @foreach ($c['app']['bullets'] as $b)
                        <li class="flex gap-3">
                            <span class="mt-0.5 grid size-6 shrink-0 place-items-center rounded-full bg-gold-100 text-gold-700"><x-heroicon-s-check class="size-4"/></span>
                            <span class="text-slate-700">{{ $b }}</span>
                        </li>
                    @endforeach
                </ul>
                @if ($c['app']['note'])
                    <p class="mt-8 text-xs text-slate-500">{{ $c['app']['note'] }}</p>
                @endif
            </div>

            <div class="landing-phones" data-reveal>
                @foreach ($c['app']['screenshots'] as $i => $shot)
                    <figure class="landing-phone landing-phone-{{ count($c['app']['screenshots']) === 1 ? 'solo' : $i }}">
                        <div class="landing-phone-frame">
                            <span class="landing-phone-notch" aria-hidden="true"></span>
                            <img src="{{ $shot['url'] }}" alt="Field force app: {{ $shot['caption'] }}" decoding="async" class="block size-full object-cover object-top">
                        </div>
                        <figcaption class="mt-3 text-center text-sm font-medium text-slate-600">{{ $shot['caption'] }}</figcaption>
                    </figure>
                @endforeach
            </div>
        </div>
    </section>

    {{-- Call to action --}}
    <section class="px-4 pb-24 sm:px-6 lg:px-8">
        <div data-reveal class="hero-surface relative mx-auto max-w-6xl overflow-hidden rounded-3xl px-6 py-14 text-center text-white sm:px-12">
            <div class="hex-pattern absolute inset-0" aria-hidden="true"></div>
            <div class="relative">
                <h2 class="font-display text-3xl font-bold sm:text-4xl">{{ $c['cta']['title'] }}</h2>
                <p class="mx-auto mt-4 max-w-2xl text-lg text-white/80">{{ $c['cta']['text'] }}</p>
                <a href="{{ $c['cta']['button_url'] }}" class="btn-gold mt-8 px-7 py-3 text-base">{{ $c['cta']['button_label'] }}</a>
            </div>
        </div>
    </section>
</main>

<footer class="border-t border-slate-200 bg-slate-50">
    <div class="mx-auto flex max-w-7xl flex-col gap-6 px-4 py-10 sm:px-6 md:flex-row md:items-center md:justify-between lg:px-8">
        <div class="flex items-center gap-3">
            <x-brand-mark :brand="$brand" class="size-9"/>
            <p class="max-w-xl text-sm text-slate-600">{{ $c['footer']['text'] }}</p>
        </div>
        <div class="text-sm text-slate-500">
            @if ($c['footer']['contact_email'])
                <a href="mailto:{{ $c['footer']['contact_email'] }}" class="font-medium text-[var(--brand-primary)] hover:underline">{{ $c['footer']['contact_email'] }}</a> ·
            @endif
            © {{ now()->year }} {{ $brand['display_name'] }}
        </div>
    </div>
</footer>
</body>
</html>

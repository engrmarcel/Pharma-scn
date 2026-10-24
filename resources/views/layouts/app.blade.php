@php
    // Pages outside the navigation registry.
    $pages = [
        'account.security' => ['finger-print', 'Account', 'Your sign-in, email verification and two-factor settings.'],
    ];
    $routeName = request()->route()?->getName();
    $routeName = \App\Support\Web\Navigation::ALIASES[$routeName] ?? $routeName; // detail pages highlight their parent tab
    $found = \App\Support\Web\Navigation::find($routeName);
    $page = $found
        ? [$found[1][2], \App\Support\Web\Navigation::SECTIONS[$found[0]][0], $found[1][4]]
        : ($pages[$routeName] ?? ['squares-2x2', 'Workspace', null]);
    $current = $nav['current'] ?? null;
    $sections = $nav['sections'] ?? [];
    $workspace = collect($sections)->except(['organization', 'admin'])->groupBy('group', preserveKeys: true);
    $tabs = ($nav['section'] ?? null) ? $sections[$nav['section']]['pages'] : [];
    $year = now()->year;
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ isset($title) ? $title.' · ' : '' }}{{ $brand['display_name'] }}</title>
    @if ($brand['favicon_url'] ?? null)
        <link rel="icon" href="{{ $brand['favicon_url'] }}">
    @endif
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>:root { --brand-primary: {{ $brand['primary_color'] }}; --brand-secondary: {{ $brand['secondary_color'] }}; }</style>
    @livewireStyles
</head>
<body class="flex min-h-screen flex-col overflow-x-clip bg-slate-50 text-slate-800 antialiased">
    <a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded focus:bg-white focus:px-3 focus:py-2">Skip to content</a>

    {{-- Utility bar --}}
    <div class="no-print bg-[var(--brand-secondary)] text-xs text-white/75">
        <div class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-2">
            <div class="flex items-center gap-5">
                <a href="mailto:{{ $brand['support_email'] }}" class="hidden items-center gap-1.5 hover:text-white sm:inline-flex">
                    <x-heroicon-o-envelope class="size-4 text-gold-400"/>{{ $brand['support_email'] }}
                </a>
                @if ($brand['support_phone'])
                    <span class="hidden items-center gap-1.5 md:inline-flex"><x-heroicon-o-phone class="size-4 text-gold-400"/>{{ $brand['support_phone'] }}</span>
                @endif
                <span class="hidden items-center gap-1.5 sm:inline-flex"><x-heroicon-o-calendar-days class="size-4 text-gold-400"/>{{ now()->format('l, j F Y') }}</span>
            </div>
            <div class="flex items-center gap-3">
                @unless (app()->isProduction())
                    <span class="rounded-full bg-gold-400/15 px-2.5 py-0.5 font-medium text-gold-300 ring-1 ring-gold-400/30">Non-production<span class="hidden sm:inline"> environment</span></span>
                @endunless
                @if ($current)
                    <span class="hidden items-center gap-1.5 text-white lg:inline-flex">
                        <x-heroicon-o-building-office-2 class="size-4 text-gold-400"/>{{ $current->organization->name }}
                    </span>
                @endif
            </div>
        </div>
    </div>

    {{-- Main header --}}
    <header data-sticky-header class="no-print sticky top-0 z-30 border-b border-slate-200 bg-white/95 backdrop-blur transition-shadow" x-data="{ mobile: false }">
        <div class="mx-auto flex h-[72px] max-w-7xl items-center justify-between gap-6 px-4">
            <a href="{{ route('dashboard') }}" class="flex shrink-0 items-center gap-3">
                <x-brand-mark :brand="$brand"/>
                <span class="leading-tight">
                    <span class="font-display block text-lg font-bold text-navy-950">{{ $brand['display_name'] }}</span>
                    <span class="hidden text-[11px] font-medium uppercase tracking-[0.2em] text-gold-600 sm:block">Pharmaceutical supply chain</span>
                </span>
            </a>

            @auth
                <nav class="hidden items-center gap-1 lg:flex" aria-label="Main">
                    <a href="{{ route('dashboard') }}" class="nav-link {{ request()->routeIs('dashboard') ? 'is-active' : '' }}">
                        <x-heroicon-o-home class="size-4 opacity-70"/>Dashboard
                    </a>

                    @if ($workspace->isNotEmpty())
                        <x-nav.dropdown label="Workspace" icon="squares-2x2" align="center" width="w-[min(72rem,calc(100vw-2rem))]" :active="($nav['section'] ?? null) && ! in_array($nav['section'], ['organization', 'admin'], true)">
                            <div class="grid max-h-[75vh] gap-x-6 gap-y-4 overflow-y-auto p-2 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
                                @foreach (\App\Support\Web\Navigation::GROUPS as $group)
                                    @continue(! $workspace->has($group))
                                    <div>
                                        <p class="mb-2 px-2 text-[11px] font-semibold uppercase tracking-[0.18em] text-gold-600">{{ $group }}</p>
                                        @foreach ($workspace[$group] as $key => $section)
                                            <div class="mb-3">
                                                <p class="flex items-center gap-1.5 px-2 text-sm font-semibold text-navy-950">
                                                    <x-dynamic-component :component="'heroicon-o-'.$section['icon']" class="size-4 text-[var(--brand-primary)]"/>{{ $section['label'] }}
                                                </p>
                                                <ul class="mt-1">
                                                    @foreach ($section['pages'] as $p)
                                                        <li><a href="{{ route($p['route']) }}" role="menuitem" title="{{ $p['description'] }}"
                                                               class="block rounded-md px-2 py-1 pl-7 text-sm text-slate-600 hover:bg-navy-50 hover:text-[var(--brand-primary)] {{ $routeName === $p['route'] ? 'bg-navy-50 font-medium text-[var(--brand-primary)]' : '' }}">{{ $p['label'] }}</a></li>
                                                    @endforeach
                                                </ul>
                                            </div>
                                        @endforeach
                                    </div>
                                @endforeach
                            </div>
                        </x-nav.dropdown>
                    @endif

                    @if ($nav['memberships']->isNotEmpty())
                        <x-nav.dropdown label="Organization" icon="building-office-2" width="w-96">
                            <p class="px-3 pb-2 pt-1 text-xs font-semibold uppercase tracking-wider text-slate-400">Switch organization</p>
                            <div class="max-h-80 overflow-y-auto">
                                @foreach ($nav['memberships'] as $m)
                                    <form method="POST" action="{{ route('organizations.switch') }}">
                                        @csrf
                                        <input type="hidden" name="organization_id" value="{{ $m->organization_id }}">
                                        <button class="group menu-item w-full items-center" role="menuitem" @disabled($current?->is($m))>
                                            <span class="menu-icon"><x-heroicon-o-building-office class="size-5"/></span>
                                            <span class="min-w-0 flex-1">
                                                <span class="block truncate text-sm font-semibold text-navy-900">{{ $m->organization->name }}</span>
                                                <span class="block text-xs text-slate-500">{{ $m->organization->type->label() }} · {{ $m->organization->country_code }}</span>
                                            </span>
                                            @if ($current?->is($m))
                                                <x-heroicon-s-check-circle class="size-5 text-emerald-600" aria-label="Current"/>
                                            @endif
                                        </button>
                                    </form>
                                @endforeach
                            </div>
                            @if (isset($sections['organization']))
                                <p class="mt-2 border-t border-slate-100 px-3 pb-1 pt-3 text-xs font-semibold uppercase tracking-wider text-slate-400">{{ $current?->organization->name }}</p>
                                @foreach ($sections['organization']['pages'] as $p)
                                    <x-nav.item :href="route($p['route'])" :icon="$p['icon']" :title="$p['label']" :active="$routeName === $p['route']"/>
                                @endforeach
                            @endif
                        </x-nav.dropdown>
                    @endif

                    @if (isset($sections['admin']))
                        <x-nav.dropdown label="Administration" icon="cog-6-tooth" width="w-[min(40rem,calc(100vw-2rem))]" align="right" :active="request()->routeIs('admin.*')">
                            <div class="grid max-h-[75vh] gap-1 overflow-y-auto sm:grid-cols-2">
                                @foreach ($sections['admin']['pages'] as $p)
                                    <x-nav.item :href="route($p['route'])" :icon="$p['icon']" :title="$p['label']" :active="$routeName === $p['route']"/>
                                @endforeach
                            </div>
                        </x-nav.dropdown>
                    @endif
                </nav>

                <div class="flex items-center gap-2">
                    <x-nav.dropdown width="w-72" align="right">
                        <x-slot:label>
                            <span class="grid size-9 place-items-center rounded-full bg-gradient-to-br from-navy-600 to-navy-900 text-xs font-bold text-white ring-2 ring-gold-400/60">{{ $nav['initials'] }}</span>
                            <span class="sr-only">Account menu</span>
                        </x-slot:label>
                        <div class="border-b border-slate-100 px-3 pb-3 pt-2">
                            <p class="truncate text-sm font-semibold text-navy-900">{{ auth()->user()->name }}</p>
                            <p class="truncate text-xs text-slate-500">{{ auth()->user()->email }}</p>
                        </div>
                        <div class="pt-1">
                            <x-nav.item :href="route('account.security')" icon="finger-print" title="Account security" description="Password, email and two-factor."/>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button class="group menu-item w-full items-center" role="menuitem">
                                    <span class="menu-icon"><x-heroicon-o-arrow-right-start-on-rectangle class="size-5"/></span>
                                    <span class="text-sm font-semibold text-navy-900">Sign out</span>
                                </button>
                            </form>
                        </div>
                    </x-nav.dropdown>

                    <button type="button" class="rounded-md p-2 text-navy-900 hover:bg-slate-100 lg:hidden" @click="mobile = ! mobile" :aria-expanded="mobile.toString()" aria-label="Menu">
                        <x-heroicon-o-bars-3 class="size-6" x-show="! mobile"/>
                        <x-heroicon-o-x-mark class="size-6" x-show="mobile" x-cloak/>
                    </button>
                </div>
            @endauth
        </div>

        @auth
            {{-- Mobile menu --}}
            <nav x-cloak x-show="mobile" x-transition class="max-h-[80vh] overflow-y-auto border-t border-slate-200 bg-white lg:hidden" aria-label="Mobile">
                <div class="mx-auto max-w-7xl space-y-1 px-4 py-3">
                    <x-nav.item :href="route('dashboard')" icon="home" title="Dashboard"/>
                    @foreach ($sections as $key => $section)
                        <details class="group/m rounded-lg" @if (($nav['section'] ?? null) === $key) open @endif>
                            <summary class="flex cursor-pointer list-none items-center gap-3 rounded-lg p-3 text-sm font-semibold text-navy-900 hover:bg-navy-50">
                                <x-dynamic-component :component="'heroicon-o-'.$section['icon']" class="size-5 text-[var(--brand-primary)]"/>
                                <span class="flex-1">{{ $section['label'] }}</span>
                                <x-heroicon-m-chevron-down class="size-4 opacity-60 transition group-open/m:rotate-180"/>
                            </summary>
                            <ul class="pb-2 pl-11">
                                @foreach ($section['pages'] as $p)
                                    <li><a href="{{ route($p['route']) }}" class="block rounded-md px-2 py-1.5 text-sm text-slate-600 hover:bg-navy-50 {{ $routeName === $p['route'] ? 'font-medium text-[var(--brand-primary)]' : '' }}">{{ $p['label'] }}</a></li>
                                @endforeach
                            </ul>
                        </details>
                    @endforeach
                    <x-nav.item :href="route('account.security')" icon="finger-print" title="Account security"/>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button class="group menu-item w-full items-center">
                            <span class="menu-icon"><x-heroicon-o-arrow-right-start-on-rectangle class="size-5"/></span>
                            <span class="text-sm font-semibold text-navy-900">Sign out</span>
                        </button>
                    </form>
                </div>
            </nav>
        @endauth
    </header>

    <main id="main" class="flex-1">
        @hasSection('hero')
            @yield('hero')
        @elseif (isset($title))
            {{-- Page banner --}}
            <section class="no-print hero-surface relative overflow-hidden text-white">
                <div class="hex-pattern absolute inset-0" aria-hidden="true"></div>
                <div class="relative mx-auto flex max-w-7xl items-center gap-4 px-4 {{ request()->routeIs('pos.*') ? 'py-4' : 'py-9' }}">
                    <span class="grid size-12 shrink-0 place-items-center rounded-xl bg-white/10 text-gold-300 ring-1 ring-white/20">
                        <x-dynamic-component :component="'heroicon-o-'.$page[0]" class="size-6"/>
                    </span>
                    <div>
                        <nav class="flex items-center gap-1.5 text-xs text-white/60" aria-label="Breadcrumb">
                            <a href="{{ route('dashboard') }}" class="hover:text-white">Home</a>
                            <x-heroicon-m-chevron-right class="size-3"/>{{ $page[1] }}
                        </nav>
                        <h1 class="font-display text-2xl font-bold sm:text-3xl">{{ $title }}</h1>
                        @if ($page[2])
                            <p class="mt-1 text-sm text-white/70">{{ $page[2] }}</p>
                        @endif
                    </div>
                </div>
                <div class="h-1 bg-gradient-to-r from-gold-500 via-gold-300 to-transparent"></div>
            </section>
            @if (count($tabs) > 1)
                {{-- Section tabs: the other pages of this area --}}
                <nav class="no-print border-b border-slate-200 bg-white" aria-label="{{ $sections[$nav['section']]['label'] }}">
                    <div class="mx-auto flex max-w-7xl gap-1 overflow-x-auto px-4">
                        @foreach ($tabs as $t)
                            <a href="{{ route($t['route']) }}" @if ($routeName === $t['route']) aria-current="page" @endif
                               class="section-tab {{ $routeName === $t['route'] ? 'is-active' : '' }}">
                                <x-dynamic-component :component="'heroicon-o-'.$t['icon']" class="size-4 opacity-70"/>{{ $t['label'] }}
                            </a>
                        @endforeach
                    </div>
                </nav>
            @endif
        @endif

        <x-ui.toasts/>

        <div class="mx-auto max-w-7xl px-4 py-8">
            @if (session('status'))
                <div role="status" class="mb-6 flex items-start gap-3 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                    <x-heroicon-o-check-circle class="size-5 shrink-0"/>{{ session('status') }}
                </div>
            @endif
            {{ $slot ?? '' }}
            @yield('content')
        </div>
    </main>

    <footer class="no-print mt-8 bg-[var(--brand-secondary)] text-sm text-white/70">
        <div class="h-1 bg-gradient-to-r from-gold-600 via-gold-300 to-gold-600"></div>
        <div class="mx-auto grid max-w-7xl gap-8 px-4 py-12 sm:grid-cols-2 lg:grid-cols-4">
            <div class="lg:col-span-2">
                <div class="flex items-center gap-3">
                    <x-brand-mark :brand="$brand"/>
                    <span class="font-display text-lg font-bold text-white">{{ $brand['display_name'] }}</span>
                </div>
                <p class="mt-4 max-w-md leading-relaxed">
                    One network for manufacturers, distributors, wholesalers, pharmacies and hospitals:
                    batch-level stock, verified trading partners and an audit trail for every movement.
                </p>
            </div>
            <div>
                <h2 class="eyebrow mb-4">Workspace</h2>
                <ul class="space-y-2">
                    <li><a href="{{ route('dashboard') }}" class="inline-flex items-center gap-2 hover:text-white"><x-heroicon-m-chevron-right class="size-4 text-gold-400"/>Dashboard</a></li>
                    <li><a href="{{ route('catalogue') }}" class="inline-flex items-center gap-2 hover:text-white"><x-heroicon-m-chevron-right class="size-4 text-gold-400"/>Product catalogue</a></li>
                    @auth
                        <li><a href="{{ route('account.security') }}" class="inline-flex items-center gap-2 hover:text-white"><x-heroicon-m-chevron-right class="size-4 text-gold-400"/>Account security</a></li>
                    @endauth
                </ul>
            </div>
            <div>
                <h2 class="eyebrow mb-4">Support</h2>
                <ul class="space-y-3">
                    <li class="flex items-center gap-2"><x-heroicon-o-envelope class="size-4 text-gold-400"/><a href="mailto:{{ $brand['support_email'] }}" class="hover:text-white">{{ $brand['support_email'] }}</a></li>
                    @if ($brand['support_phone'])
                        <li class="flex items-center gap-2"><x-heroicon-o-phone class="size-4 text-gold-400"/>{{ $brand['support_phone'] }}</li>
                    @endif
                    <li class="flex items-center gap-2"><x-heroicon-o-lifebuoy class="size-4 text-gold-400"/>Include the request ID shown on any error.</li>
                </ul>
            </div>
        </div>
        <div class="border-t border-white/10">
            <div class="mx-auto flex max-w-7xl flex-col gap-2 px-4 py-5 text-xs text-white/50 sm:flex-row sm:items-center sm:justify-between">
                <span>&copy; {{ $year }} {{ $brand['display_name'] }}. All rights reserved.</span>
                <span>Platform verification is the platform's own review. It is not regulatory approval.</span>
            </div>
        </div>
    </footer>
    @livewireScripts
</body>
</html>

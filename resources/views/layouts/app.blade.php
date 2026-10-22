@php
    $pages = [
        'catalogue' => ['beaker', 'Workspace', 'Search the shared product master: identifiers, ingredients and pack sizes.'],
        'pos.register' => ['building-storefront', 'Point of sale', null],
        'field-force.dashboard' => ['map-pin', 'Field force', 'Visits, plans, targets and pipeline of the team you supervise.'],
        'analytics.executive' => ['chart-bar', 'Analytics', 'Headline figures across sales, stock, expiry risk and operations.'],
        'account.security' => ['finger-print', 'Account', 'Your sign-in, email verification and two-factor settings.'],
        'admin.verifications' => ['shield-check', 'Administration', 'Review organization documents submitted for platform verification.'],
        'admin.documentation' => ['book-open', 'Administration', 'Setup, administration, user guide, flows, API and integration: read or download as PDF.'],
        'admin.landing-page' => ['globe-alt', 'Administration', 'Edit the public landing page: hero, network infographic, features and app screens.'],
    ];
    $page = $pages[request()->route()?->getName()] ?? ['squares-2x2', 'Workspace', null];
    $current = $nav['current'] ?? null;
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

                    <x-nav.dropdown label="Operations" icon="squares-2x2" :active="request()->routeIs('catalogue', 'pos.*', 'field-force.*', 'analytics.*')">
                        <x-nav.item :href="route('catalogue')" icon="beaker" title="Product catalogue"
                                    description="Search the product master by name, SKU, GTIN or registration number." :active="request()->routeIs('catalogue')"/>
                        @if ($nav['pos'])
                            <x-nav.item :href="route('pos.register')" icon="building-storefront" title="POS register"
                                        description="Sell over the counter, take payment and print receipts." :active="request()->routeIs('pos.*')"/>
                        @endif
                        @if ($nav['field_dashboard'] ?? false)
                            <x-nav.item :href="route('field-force.dashboard')" icon="map-pin" title="Field force dashboard"
                                        description="Team visits, plan approvals, targets and pipeline." :active="request()->routeIs('field-force.*')"/>
                        @endif
                        @if ($nav['analytics'] ?? false)
                            <x-nav.item :href="route('analytics.executive')" icon="chart-bar" title="Executive dashboard"
                                        description="Sales, stock, expiry risk and operations at a glance." :active="request()->routeIs('analytics.*')"/>
                        @endif
                        <div class="mt-1 border-t border-slate-100 px-3 pb-1 pt-3 text-xs text-slate-500">
                            Inventory, procurement, sales and finance are available through the API.
                        </div>
                    </x-nav.dropdown>

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
                        </x-nav.dropdown>
                    @endif

                    @if ($nav['admin'])
                        <x-nav.dropdown label="Administration" icon="cog-6-tooth" :active="request()->routeIs('admin.*')">
                            @if ($nav['admin_verifications'] ?? true)
                                <x-nav.item :href="route('admin.verifications')" icon="shield-check" title="Verification queue"
                                            description="Review documents and decide platform verification." :active="request()->routeIs('admin.verifications')"/>
                            @endif
                            @if ($nav['admin_docs'] ?? false)
                                <x-nav.item :href="route('admin.documentation')" icon="book-open" title="Documentation"
                                            description="Manuals and API guide; download all as PDF." :active="request()->routeIs('admin.documentation')"/>
                            @endif
                            @if ($nav['admin_landing'] ?? false)
                                <x-nav.item :href="route('admin.landing-page')" icon="globe-alt" title="Landing page"
                                            description="Edit the public page: hero, network, features and app screens." :active="request()->routeIs('admin.landing-page')"/>
                            @endif
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
            <nav x-cloak x-show="mobile" x-transition class="border-t border-slate-200 bg-white lg:hidden" aria-label="Mobile">
                <div class="mx-auto max-w-7xl space-y-1 px-4 py-3">
                    <x-nav.item :href="route('dashboard')" icon="home" title="Dashboard"/>
                    <x-nav.item :href="route('catalogue')" icon="beaker" title="Product catalogue"/>
                    @if ($nav['pos'])
                        <x-nav.item :href="route('pos.register')" icon="building-storefront" title="POS register"/>
                    @endif
                    @if ($nav['field_dashboard'] ?? false)
                        <x-nav.item :href="route('field-force.dashboard')" icon="map-pin" title="Field force dashboard"/>
                    @endif
                    @if ($nav['admin'])
                        @if ($nav['admin_verifications'] ?? true)<x-nav.item :href="route('admin.verifications')" icon="shield-check" title="Verification queue"/>@endif
                        @if ($nav['admin_docs'] ?? false)<x-nav.item :href="route('admin.documentation')" icon="book-open" title="Documentation"/>@endif
                        @if ($nav['admin_landing'] ?? false)<x-nav.item :href="route('admin.landing-page')" icon="globe-alt" title="Landing page"/>@endif
                    @endif
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
        @endif

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

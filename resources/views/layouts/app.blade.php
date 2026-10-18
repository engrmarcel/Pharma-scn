@php($brand = app(\App\Domain\Platform\Services\BrandingService::class)->platform())
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ isset($title) ? $title.' · ' : '' }}{{ $brand['display_name'] }}</title>
    @if ($brand['favicon_url'])
        <link rel="icon" href="{{ $brand['favicon_url'] }}">
    @endif
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>:root { --brand-primary: {{ $brand['primary_color'] }}; --brand-secondary: {{ $brand['secondary_color'] }}; }</style>
    @livewireStyles
</head>
<body class="min-h-screen bg-slate-50 text-slate-900 antialiased">
    <header class="bg-[var(--brand-secondary)] text-white">
        <div class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-3">
            <a href="{{ route('dashboard') }}" class="flex items-center gap-2 font-semibold">
                @if ($brand['logo_url'])
                    <img src="{{ $brand['logo_url'] }}" alt="" class="h-7">
                @endif
                {{ $brand['display_name'] }}
            </a>
            @auth
                <nav class="flex items-center gap-5 text-sm">
                    <a href="{{ route('dashboard') }}" class="hover:underline">Dashboard</a>
                    <a href="{{ route('catalogue') }}" class="hover:underline">Catalogue</a>
                    <a href="{{ route('account.security') }}" class="hover:underline">Security</a>
                    @can('platform.organizations.view')
                        <a href="{{ route('admin.verifications') }}" class="hover:underline">Verification queue</a>
                    @endcan
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button class="rounded border border-white/30 px-2 py-1 hover:bg-white/10">Sign out</button>
                    </form>
                </nav>
            @endauth
        </div>
    </header>

    <main class="mx-auto max-w-7xl px-4 py-8">
        @if (session('status'))
            <div class="mb-6 rounded border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('status') }}</div>
        @endif
        {{ $slot ?? '' }}
        @yield('content')
    </main>

    <footer class="mx-auto max-w-7xl px-4 pb-8 text-xs text-slate-500">
        Support: {{ $brand['support_email'] }}@if ($brand['support_phone']) · {{ $brand['support_phone'] }}@endif
    </footer>
    @livewireScripts
</body>
</html>

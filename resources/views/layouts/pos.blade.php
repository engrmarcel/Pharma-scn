<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
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
{{-- Full-screen till: no site navigation, everything within the window, operable from the keyboard. --}}
<body class="h-full overflow-hidden bg-slate-100 text-slate-800 antialiased">
    <x-ui.toasts/>
    {{ $slot }}
    @livewireScripts
</body>
</html>

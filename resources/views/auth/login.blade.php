@extends('layouts.guest')

@section('title', 'Sign in')

@section('content')
    <div class="mb-8">
        <span class="grid size-12 place-items-center rounded-xl bg-navy-50 text-[var(--brand-primary)] ring-1 ring-navy-100">
            <x-heroicon-o-lock-closed class="size-6"/>
        </span>
        <h1 class="font-display mt-5 text-3xl font-bold text-navy-950">Welcome back</h1>
        <p class="mt-2 text-sm text-slate-500">Sign in to your workspace to continue.</p>
    </div>

    <form method="POST" action="{{ route('login.store') }}" class="space-y-5" x-data="{ busy: false, show: false }" @submit="busy = true">
        @csrf

        @error('email')
            <div role="alert" class="flex items-start gap-2 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2.5 text-sm text-rose-800">
                <x-heroicon-o-exclamation-circle class="size-5 shrink-0"/>{{ $message }}
            </div>
        @enderror

        <div>
            <label for="email" class="form-label">Email address</label>
            <div class="relative">
                <x-heroicon-o-envelope class="pointer-events-none absolute left-3 top-1/2 size-5 -translate-y-1/2 text-slate-400"/>
                <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username"
                       placeholder="you@organization.com" class="form-input pl-10">
            </div>
        </div>

        <div>
            <label for="password" class="form-label">Password</label>
            <div class="relative">
                <x-heroicon-o-key class="pointer-events-none absolute left-3 top-1/2 size-5 -translate-y-1/2 text-slate-400"/>
                <input id="password" type="password" :type="show ? 'text' : 'password'" name="password" required autocomplete="current-password"
                       placeholder="••••••••" class="form-input pl-10 pr-11">
                <button type="button" class="absolute right-2 top-1/2 -translate-y-1/2 rounded-md p-1.5 text-slate-400 hover:text-navy-900"
                        @click="show = ! show" :aria-label="show ? 'Hide password' : 'Show password'" aria-label="Show password">
                    <x-heroicon-o-eye class="size-5" x-show="! show"/>
                    <x-heroicon-o-eye-slash class="size-5" x-show="show" x-cloak/>
                </button>
            </div>
        </div>

        <label class="flex items-center gap-2 text-sm text-slate-600">
            <input type="checkbox" name="remember" class="size-4 rounded border-slate-300 accent-[var(--brand-primary)]"> Keep me signed in on this device
        </label>

        <button class="btn-primary w-full py-3 text-base" :disabled="busy">
            <svg x-show="busy" x-cloak class="size-5 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-opacity=".25" stroke-width="3"/>
                <path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
            </svg>
            Sign in
            <x-heroicon-m-arrow-right class="size-5" x-show="! busy"/>
        </button>
    </form>

    <div class="mt-8 flex items-center gap-3 rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-xs text-slate-500">
        <x-heroicon-o-shield-check class="size-5 shrink-0 text-emerald-600"/>
        Access is role-based and every change is recorded in the audit trail.
    </div>
@endsection

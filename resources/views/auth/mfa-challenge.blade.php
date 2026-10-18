@extends('layouts.guest')

@section('title', 'Two-factor authentication')

@section('content')
    <div class="mb-8">
        <span class="grid size-12 place-items-center rounded-xl bg-navy-50 text-[var(--brand-primary)] ring-1 ring-navy-100">
            <x-heroicon-o-device-phone-mobile class="size-6"/>
        </span>
        <h1 class="font-display mt-5 text-3xl font-bold text-navy-950">Two-factor authentication</h1>
        <p class="mt-2 text-sm text-slate-500">Enter the 6-digit code from your authenticator app, or one of your recovery codes.</p>
    </div>

    <form method="POST" action="{{ route('login.mfa.verify') }}" class="space-y-5" x-data="{ busy: false, recovery: false }" @submit="busy = true">
        @csrf

        @error('code')
            <div role="alert" class="flex items-start gap-2 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2.5 text-sm text-rose-800">
                <x-heroicon-o-exclamation-circle class="size-5 shrink-0"/>{{ $message }}
            </div>
        @enderror

        <div x-show="! recovery">
            <label for="code" class="form-label">Authentication code</label>
            <input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" autofocus placeholder="000000"
                   class="form-input text-center font-mono text-2xl tracking-[0.5em]">
        </div>

        <div x-show="recovery" x-cloak>
            <label for="recovery_code" class="form-label">Recovery code</label>
            <input id="recovery_code" name="recovery_code" autocomplete="off" maxlength="20" class="form-input font-mono">
        </div>

        <button type="button" class="text-sm font-medium text-[var(--brand-primary)] hover:underline" @click="recovery = ! recovery"
                x-text="recovery ? 'Use an authentication code instead' : 'Use a recovery code instead'">Use a recovery code instead</button>

        <button class="btn-primary w-full py-3 text-base" :disabled="busy">
            <x-heroicon-o-shield-check class="size-5"/>Verify
        </button>
    </form>
@endsection

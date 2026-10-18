@extends('layouts.app')

@section('content')
    <div class="mx-auto max-w-sm">
        <h1 class="mb-2 text-2xl font-semibold">Two-factor authentication</h1>
        <p class="mb-6 text-sm text-slate-600">Enter the 6-digit code from your authenticator app, or one of your recovery codes.</p>

        <form method="POST" action="{{ route('login.mfa.verify') }}" class="space-y-4 rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
            @csrf
            <label class="block text-sm">
                <span class="mb-1 block font-medium">Authentication code</span>
                <input name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" autofocus
                       class="w-full rounded border border-slate-300 px-3 py-2 tracking-widest focus:border-[var(--brand-primary)] focus:outline-none">
            </label>
            <label class="block text-sm">
                <span class="mb-1 block font-medium">Or recovery code</span>
                <input name="recovery_code" autocomplete="off" maxlength="20"
                       class="w-full rounded border border-slate-300 px-3 py-2 focus:border-[var(--brand-primary)] focus:outline-none">
            </label>
            @error('code')
                <p class="text-sm text-red-600">{{ $message }}</p>
            @enderror
            <button class="w-full rounded bg-[var(--brand-primary)] px-4 py-2 font-medium text-white hover:opacity-90">Verify</button>
        </form>
    </div>
@endsection

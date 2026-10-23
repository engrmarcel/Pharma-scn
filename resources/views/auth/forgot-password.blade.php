@extends('layouts.guest')

@section('title', 'Forgotten password')

@section('content')
    <div class="mb-8">
        <span class="grid size-12 place-items-center rounded-xl bg-navy-50 text-[var(--brand-primary)] ring-1 ring-navy-100">
            <x-heroicon-o-key class="size-6"/>
        </span>
        <h1 class="font-display mt-5 text-3xl font-bold text-navy-950">Reset your password</h1>
        <p class="mt-2 text-sm text-slate-500">Enter your email address and we will send you a link to choose a new password.</p>
    </div>

    @if (session('status'))
        <div role="status" class="mb-5 flex items-start gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2.5 text-sm text-emerald-800">
            <x-heroicon-o-check-circle class="size-5 shrink-0"/>{{ session('status') }}
        </div>
    @endif

    <form method="POST" action="{{ route('password.email') }}" class="space-y-5" x-data="{ busy: false }" @submit="busy = true">
        @csrf
        <div>
            <label for="email" class="form-label">Email address</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username" class="form-input">
            @error('email')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
        </div>
        <button class="btn-primary w-full py-3 text-base" :disabled="busy">Send reset link</button>
    </form>

    <p class="mt-6 text-center text-sm text-slate-500"><a href="{{ route('login') }}" class="link">&larr; Back to sign in</a></p>
@endsection

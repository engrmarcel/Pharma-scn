@extends('layouts.guest')

@section('title', 'Choose a new password')

@section('content')
    <div class="mb-8">
        <span class="grid size-12 place-items-center rounded-xl bg-navy-50 text-[var(--brand-primary)] ring-1 ring-navy-100">
            <x-heroicon-o-key class="size-6"/>
        </span>
        <h1 class="font-display mt-5 text-3xl font-bold text-navy-950">Choose a new password</h1>
        <p class="mt-2 text-sm text-slate-500">Changing your password signs you out of the mobile apps.</p>
    </div>

    <form method="POST" action="{{ route('password.update') }}" class="space-y-4" x-data="{ busy: false }" @submit="busy = true">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <div>
            <label for="email" class="form-label">Email address</label>
            <input id="email" type="email" name="email" value="{{ old('email', $email) }}" required autocomplete="username" class="form-input">
            @error('email')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
        </div>
        <div>
            <label for="password" class="form-label">New password</label>
            <input id="password" type="password" name="password" required autofocus autocomplete="new-password" class="form-input">
            @error('password')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@else<p class="mt-1 text-xs text-slate-500">At least 10 characters, with letters and numbers.</p>@enderror
        </div>
        <div>
            <label for="password_confirmation" class="form-label">Confirm new password</label>
            <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password" class="form-input">
        </div>
        <button class="btn-primary w-full py-3 text-base" :disabled="busy">Change password</button>
    </form>
@endsection

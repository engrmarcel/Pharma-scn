@extends('layouts.app')

@section('content')
    <div class="mx-auto max-w-sm">
        <h1 class="mb-6 text-2xl font-semibold">Sign in</h1>
        <form method="POST" action="{{ route('login.store') }}" class="space-y-4 rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
            @csrf
            <label class="block text-sm">
                <span class="mb-1 block font-medium">Email</span>
                <input type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username"
                       class="w-full rounded border border-slate-300 px-3 py-2 focus:border-[var(--brand-primary)] focus:outline-none">
            </label>
            <label class="block text-sm">
                <span class="mb-1 block font-medium">Password</span>
                <input type="password" name="password" required autocomplete="current-password"
                       class="w-full rounded border border-slate-300 px-3 py-2 focus:border-[var(--brand-primary)] focus:outline-none">
            </label>
            @error('email')
                <p class="text-sm text-red-600">{{ $message }}</p>
            @enderror
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="remember"> Keep me signed in
            </label>
            <button class="w-full rounded bg-[var(--brand-primary)] px-4 py-2 font-medium text-white hover:opacity-90">Sign in</button>
        </form>
    </div>
@endsection

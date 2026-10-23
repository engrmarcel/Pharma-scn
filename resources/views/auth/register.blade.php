@extends('layouts.guest')

@section('title', 'Create an account')

@section('content')
    <div class="mb-8">
        <span class="grid size-12 place-items-center rounded-xl bg-navy-50 text-[var(--brand-primary)] ring-1 ring-navy-100">
            <x-heroicon-o-user-plus class="size-6"/>
        </span>
        <h1 class="font-display mt-5 text-3xl font-bold text-navy-950">Create your account</h1>
        <p class="mt-2 text-sm text-slate-500">Then register your organization, or join one that invited you.</p>
    </div>

    <form method="POST" action="{{ route('register.store') }}" class="space-y-4" x-data="{ busy: false }" @submit="busy = true">
        @csrf
        <div>
            <label for="name" class="form-label">Full name</label>
            <input id="name" name="name" value="{{ old('name') }}" required autofocus autocomplete="name" class="form-input">
            @error('name')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
        </div>
        <div>
            <label for="email" class="form-label">Work email</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" required autocomplete="username" class="form-input">
            @error('email')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
        </div>
        <div>
            <label for="phone" class="form-label">Phone <span class="font-normal text-slate-400">(optional)</span></label>
            <input id="phone" name="phone" value="{{ old('phone') }}" autocomplete="tel" class="form-input">
            @error('phone')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="password" class="form-label">Password</label>
                <input id="password" type="password" name="password" required autocomplete="new-password" class="form-input">
            </div>
            <div>
                <label for="password_confirmation" class="form-label">Confirm password</label>
                <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password" class="form-input">
            </div>
        </div>
        @error('password')<p class="text-xs text-rose-600">{{ $message }}</p>@else<p class="text-xs text-slate-500">At least 10 characters, with letters and numbers.</p>@enderror

        <button class="btn-primary w-full py-3 text-base" :disabled="busy">Create account <x-heroicon-m-arrow-right class="size-5"/></button>
    </form>

    <p class="mt-6 text-center text-sm text-slate-500">Already have an account? <a href="{{ route('login') }}" class="link">Sign in</a></p>
@endsection

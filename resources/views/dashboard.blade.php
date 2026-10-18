@extends('layouts.app')

@section('content')
    <h1 class="mb-1 text-2xl font-semibold">Welcome, {{ auth()->user()->name }}</h1>
    @if ($platformRoles->isNotEmpty())
        <p class="mb-6 text-sm text-slate-600">Platform roles: {{ $platformRoles->join(', ') }}</p>
    @endif

    <section class="mt-6">
        <h2 class="mb-3 text-lg font-semibold">Your organizations</h2>
        @forelse ($memberships as $membership)
            @php($org = $membership->organization)
            <div class="mb-3 flex items-center justify-between rounded-lg border bg-white p-4 shadow-sm {{ $current?->is($membership) ? 'border-[var(--brand-primary)]' : 'border-slate-200' }}">
                <div>
                    <div class="font-medium">{{ $org->name }}</div>
                    <div class="text-sm text-slate-600">
                        {{ $org->type->label() }} · {{ $org->country_code }} ·
                        Roles: {{ $membership->roles->pluck('name')->join(', ') }}
                    </div>
                    <div class="mt-1 text-xs text-slate-500">
                        Platform verification: <span class="font-medium">{{ str_replace('_', ' ', $org->verification_status->value) }}</span>
                        · Modules: {{ $org->modules->where('enabled', true)->pluck('module')->join(', ') ?: 'none' }}
                        @unless ($org->isActive()) · <span class="font-medium text-red-700">Suspended</span>@endunless
                    </div>
                </div>
                @if ($current?->is($membership))
                    <span class="text-sm font-medium text-[var(--brand-primary)]">Current</span>
                @else
                    <form method="POST" action="{{ route('organizations.switch') }}">
                        @csrf
                        <input type="hidden" name="organization_id" value="{{ $org->id }}">
                        <button class="rounded border border-slate-300 px-3 py-1 text-sm hover:bg-slate-50">Switch to</button>
                    </form>
                @endif
            </div>
        @empty
            <p class="text-sm text-slate-600">You are not a member of any organization yet. Register one through the API or accept an invitation.</p>
        @endforelse
    </section>

    <p class="mt-8 text-xs text-slate-500">
        "Platform verification" reflects this platform's own review of submitted documents. It is not an approval or statement by any regulatory authority.
    </p>
@endsection

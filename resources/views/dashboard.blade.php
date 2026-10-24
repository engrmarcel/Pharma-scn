@extends('layouts.app')

@php
    $user = auth()->user();
    $hour = now()->hour;
    $greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
    $org = $current?->organization;
    $verificationTones = [
        'verified' => 'bg-emerald-400/15 text-emerald-200 ring-emerald-300/30',
        'pending_review' => 'bg-amber-400/15 text-amber-200 ring-amber-300/30',
        'changes_requested' => 'bg-amber-400/15 text-amber-200 ring-amber-300/30',
        'rejected' => 'bg-rose-400/15 text-rose-200 ring-rose-300/30',
        'revoked' => 'bg-rose-400/15 text-rose-200 ring-rose-300/30',
    ];
    $moduleIcons = [
        'inventory' => 'archive-box', 'procurement' => 'truck', 'sales' => 'clipboard-document-list', 'finance' => 'banknotes',
        'pos' => 'building-storefront', 'field_force' => 'map-pin', 'traceability' => 'qr-code', 'marketplace' => 'globe-alt',
    ];
    $cards = $metrics['cards'];
@endphp

@section('hero')
    <section class="hero-surface relative overflow-hidden text-white">
        <div class="hex-pattern absolute inset-0" aria-hidden="true"></div>
        <span class="animate-float-slow absolute -right-10 top-10 hidden size-64 rounded-full border border-white/10 lg:block" aria-hidden="true"></span>
        <div class="relative mx-auto grid max-w-7xl gap-8 px-4 pb-24 pt-10 lg:grid-cols-[1.4fr_1fr] lg:items-center">
            <div class="animate-fade-up">
                <p class="eyebrow text-gold-300">{{ now()->format('l, j F Y') }}</p>
                <h1 class="font-display mt-3 text-3xl font-bold sm:text-4xl">{{ $greeting }}, {{ \Illuminate\Support\Str::before($user->name, ' ') ?: $user->name }}</h1>
                <p class="mt-3 max-w-2xl text-white/75">
                    @if ($org && $tenantActive)
                        You are working in <span class="font-semibold text-white">{{ $org->name }}</span>. Here is where things stand today.
                    @elseif ($org)
                        <span class="font-semibold text-white">{{ $org->name }}</span> is not available right now. See its status below.
                    @elseif ($memberships->isNotEmpty())
                        Choose an organization below to see its stock, orders and sales figures.
                    @else
                        Welcome to the network. Register your organization, or accept an invitation to join one.
                    @endif
                </p>
                <div class="mt-6 flex flex-wrap gap-3">
                    @if ($memberships->isEmpty())
                        <a href="{{ route('onboarding.organization') }}" class="btn-gold"><x-heroicon-o-building-office-2 class="size-5"/>Register your organization</a>
                        <a href="{{ route('invitations.accept') }}" class="btn-ghost-light"><x-heroicon-o-envelope-open class="size-5"/>Accept an invitation</a>
                    @endif
                    @if ($nav['pos'])
                        <a href="{{ route('pos.register') }}" class="btn-gold"><x-heroicon-o-building-storefront class="size-5"/>Open POS terminal</a>
                    @endif
                    <a href="{{ route('catalogue') }}" class="btn-ghost-light"><x-heroicon-o-beaker class="size-5"/>Product catalogue</a>
                    <a href="{{ route('account.security') }}" class="btn-ghost-light"><x-heroicon-o-finger-print class="size-5"/>Account security</a>
                </div>
            </div>

            @if ($org)
                <div class="animate-fade-up rounded-2xl border border-white/15 bg-white/[0.07] p-6 backdrop-blur-sm" style="animation-delay: 120ms">
                    <div class="flex items-start gap-4">
                        <span class="grid size-12 shrink-0 place-items-center rounded-xl bg-gold-400 text-navy-950 shadow-lg">
                            <x-heroicon-o-building-office-2 class="size-6"/>
                        </span>
                        <div class="min-w-0">
                            <p class="truncate font-semibold">{{ $org->name }}</p>
                            <p class="text-sm text-white/65">{{ $org->type->label() }} · {{ $org->country_code }}</p>
                        </div>
                    </div>
                    <div class="mt-4 flex flex-wrap gap-2 text-xs">
                        <span class="badge {{ $verificationTones[$org->verification_status->value] ?? 'bg-white/10 text-white/80 ring-white/20' }}">
                            <x-heroicon-m-shield-check class="size-3.5"/>Platform verification: {{ str_replace('_', ' ', $org->verification_status->value) }}
                        </span>
                        @unless ($org->isActive())
                            <span class="badge bg-rose-400/15 text-rose-200 ring-rose-300/30"><x-heroicon-m-no-symbol class="size-3.5"/>Suspended</span>
                        @endunless
                    </div>
                    <p class="mt-4 text-xs font-semibold uppercase tracking-wider text-white/50">Your roles</p>
                    <p class="mt-1 text-sm text-white/85">{{ $current->roles->pluck('name')->join(', ') ?: 'None' }}</p>
                    <p class="mt-4 text-xs font-semibold uppercase tracking-wider text-white/50">Modules</p>
                    <div class="mt-2 flex flex-wrap gap-2">
                        @forelse ($org->modules->where('enabled', true) as $module)
                            <span class="inline-flex items-center gap-1.5 rounded-md bg-white/10 px-2 py-1 text-xs ring-1 ring-white/15">
                                <x-dynamic-component :component="'heroicon-o-'.($moduleIcons[$module->module] ?? 'squares-2x2')" class="size-3.5 text-gold-300"/>
                                {{ str_replace('_', ' ', $module->module) }}
                            </span>
                        @empty
                            <span class="text-sm text-white/60">None enabled</span>
                        @endforelse
                    </div>
                </div>
            @endif
        </div>
    </section>
@endsection

@section('content')
    {{-- Statistic cards overlap the hero --}}
    <div class="relative -mt-24">
        @if ($cards)
            <div class="grid gap-5 sm:grid-cols-2 xl:grid-cols-4">
                @foreach ($cards as $i => $card)
                    <x-stat-card :card="$card" :delay="$i * 60"/>
                @endforeach
            </div>
        @else
            <div class="card flex items-center gap-4 p-6">
                <span class="grid size-12 shrink-0 place-items-center rounded-xl bg-navy-50 text-[var(--brand-primary)]"><x-heroicon-o-chart-bar class="size-6"/></span>
                <p class="text-sm text-slate-600">
                    @if ($tenantActive)
                        No figures to show for your roles in this organization yet.
                    @else
                        Select an organization to see its stock, orders and sales figures.
                    @endif
                </p>
            </div>
        @endif
    </div>

    @if ($metrics['trend'] || $metrics['expiring'])
        <div class="mt-8 grid gap-6 lg:grid-cols-5">
            @if ($metrics['trend'])
                @php($trend = $metrics['trend'])
                <section class="card lg:col-span-3">
                    <div class="card-header">
                        <h2 class="card-title"><x-heroicon-o-chart-bar class="size-5 text-gold-500"/>Sales, last 7 days</h2>
                        <span class="text-xs text-slate-500">{{ $trend['scope'] }} · {{ $metrics['currency'] }}</span>
                    </div>
                    <div class="p-5">
                        <p class="text-2xl font-bold text-navy-950">{{ $metrics['currency'] }} {{ number_format($trend['sum'], 2) }}</p>
                        <div class="mt-6 flex items-end gap-3" role="img" aria-label="Daily invoiced sales for the last seven days">
                            @foreach ($trend['days'] as $i => $day)
                                <div class="group flex flex-1 flex-col items-center gap-2">
                                    <div class="flex h-40 w-full items-end rounded-t-md bg-slate-50">
                                        <div class="w-full origin-bottom rounded-t-md bg-gradient-to-t from-navy-700 to-[var(--brand-primary)] transition-all duration-700 group-hover:from-gold-600 group-hover:to-gold-400 {{ $loop->last ? 'ring-2 ring-gold-400/60' : '' }}"
                                             style="height: {{ max($day['height'], 2) }}%"
                                             title="{{ $day['date'] }}: {{ number_format($day['total'], 2) }}"></div>
                                    </div>
                                    <span class="text-xs {{ $loop->last ? 'font-semibold text-navy-900' : 'text-slate-500' }}">{{ $day['label'] }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </section>
            @endif

            @if ($metrics['expiring'])
                <section class="card {{ $metrics['trend'] ? 'lg:col-span-2' : 'lg:col-span-5' }}">
                    <div class="card-header">
                        <h2 class="card-title"><x-heroicon-o-clock class="size-5 text-amber-500"/>Expiring soon</h2>
                        <span class="text-xs text-slate-500">Next 90 days</span>
                    </div>
                    <ul class="divide-y divide-slate-100">
                        @foreach ($metrics['expiring'] as $batch)
                            @php($days = (int) today()->diffInDays(\Illuminate\Support\Carbon::parse($batch->expiry_date)))
                            <li class="flex items-center gap-3 px-5 py-3">
                                <span class="grid size-9 shrink-0 place-items-center rounded-lg {{ $days <= 30 ? 'bg-rose-50 text-rose-600' : 'bg-amber-50 text-amber-600' }}">
                                    <x-heroicon-o-beaker class="size-5"/>
                                </span>
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-medium text-navy-900">{{ $batch->product_name }}</p>
                                    <p class="text-xs text-slate-500">Batch {{ $batch->batch_number }} · {{ rtrim(rtrim(number_format((float) $batch->quantity, 4), '0'), '.') }} units</p>
                                </div>
                                <span class="badge {{ $days <= 30 ? 'bg-rose-50 text-rose-700 ring-rose-200' : 'bg-amber-50 text-amber-700 ring-amber-200' }}">{{ $days }} {{ \Illuminate\Support\Str::plural('day', $days) }}</span>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </div>
    @endif

    @if ($metrics['platform'])
        <section class="mt-10">
            <div class="mb-4 flex items-end justify-between">
                <div>
                    <p class="eyebrow">Platform</p>
                    <h2 class="font-display text-2xl font-bold text-navy-950">Network oversight</h2>
                    @if ($platformRoles->isNotEmpty())
                        <p class="mt-1 text-sm text-slate-500">Platform roles: {{ $platformRoles->join(', ') }}</p>
                    @endif
                </div>
            </div>
            <div class="grid gap-5 sm:grid-cols-2 xl:grid-cols-4">
                @foreach ($metrics['platform'] as $i => $card)
                    <x-stat-card :card="$card" :delay="$i * 60"/>
                @endforeach
            </div>
        </section>
    @elseif ($platformRoles->isNotEmpty())
        <p class="mt-6 text-sm text-slate-600">Platform roles: {{ $platformRoles->join(', ') }}</p>
    @endif

    <section class="mt-10">
        <p class="eyebrow">Membership</p>
        <h2 class="font-display mb-4 text-2xl font-bold text-navy-950">Your organizations</h2>
        @if ($memberships->isNotEmpty())
            <div class="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($memberships as $membership)
                    @php($o = $membership->organization)
                    @php($isCurrent = $current?->is($membership))
                    <article class="card relative flex flex-col overflow-hidden transition hover:-translate-y-0.5 hover:shadow-lg {{ $isCurrent ? 'ring-2 ring-[var(--brand-primary)]' : '' }}">
                        <div class="flex items-start gap-4 p-5">
                            <span class="grid size-12 shrink-0 place-items-center rounded-xl {{ $isCurrent ? 'bg-[var(--brand-primary)] text-white' : 'bg-navy-50 text-[var(--brand-primary)]' }}">
                                <x-heroicon-o-building-office-2 class="size-6"/>
                            </span>
                            <div class="min-w-0 flex-1">
                                <h3 class="truncate font-semibold text-navy-950">{{ $o->name }}</h3>
                                <p class="text-sm text-slate-500">{{ $o->type->label() }} · {{ $o->country_code }}</p>
                                <p class="mt-1 text-xs text-slate-500">Roles: {{ $membership->roles->pluck('name')->join(', ') }}</p>
                            </div>
                        </div>
                        <div class="flex flex-wrap gap-2 px-5 pb-4 text-xs">
                            <span class="badge {{ $o->verification_status->value === 'verified' ? 'bg-emerald-50 text-emerald-700 ring-emerald-200' : 'bg-slate-50 text-slate-600 ring-slate-200' }}">
                                <x-heroicon-m-shield-check class="size-3.5"/>Platform verification: {{ str_replace('_', ' ', $o->verification_status->value) }}
                            </span>
                            @unless ($o->isActive())
                                <span class="badge bg-rose-50 text-rose-700 ring-rose-200">Suspended</span>
                            @endunless
                        </div>
                        <div class="mt-auto flex items-center justify-between border-t border-slate-100 bg-slate-50/70 px-5 py-3">
                            <span class="truncate text-xs text-slate-500">Modules: {{ $o->modules->where('enabled', true)->pluck('module')->join(', ') ?: 'none' }}</span>
                            @if ($isCurrent)
                                <span class="inline-flex items-center gap-1 text-sm font-semibold text-[var(--brand-primary)]"><x-heroicon-s-check-circle class="size-4"/>Current</span>
                            @else
                                <form method="POST" action="{{ route('organizations.switch') }}">
                                    @csrf
                                    <input type="hidden" name="organization_id" value="{{ $o->id }}">
                                    <button class="btn-light px-3 py-1.5 text-xs">Switch to<x-heroicon-m-arrow-right class="size-3.5"/></button>
                                </form>
                            @endif
                        </div>
                    </article>
                @endforeach
            </div>
        @else
            <div class="card p-6 text-sm text-slate-600">You are not a member of any organization yet. Register one through the API or accept an invitation.</div>
        @endif
    </section>

    <p class="mt-10 flex items-start gap-2 text-xs text-slate-500">
        <x-heroicon-o-information-circle class="size-4 shrink-0"/>
        "Platform verification" reflects this platform's own review of submitted documents. It is not an approval or statement by any regulatory authority.
    </p>
@endsection

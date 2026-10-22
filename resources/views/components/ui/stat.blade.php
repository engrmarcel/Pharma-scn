@props(['label', 'value', 'hint' => null, 'tone' => 'navy'])
@php $bar = ['navy' => 'bg-[var(--brand-primary)]', 'green' => 'bg-emerald-500', 'amber' => 'bg-amber-500', 'red' => 'bg-rose-500', 'gold' => 'bg-gold-400'][$tone] ?? 'bg-[var(--brand-primary)]'; @endphp
<div {{ $attributes->merge(['class' => 'relative overflow-hidden rounded-xl border border-slate-200/80 bg-white p-4 shadow-sm']) }}>
    <span class="absolute inset-x-0 top-0 h-1 {{ $bar }}"></span>
    <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $label }}</p>
    <p class="mt-1 text-2xl font-bold tabular-nums text-navy-950">{{ $value }}</p>
    @if ($hint)<p class="mt-1 text-xs text-slate-500">{{ $hint }}</p>@endif
</div>

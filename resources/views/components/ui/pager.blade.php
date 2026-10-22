@props(['paginator' => []])
{{-- Pager for an API page ({current_page, last_page, total}); the screen keeps $page. --}}
@php
    $current = (int) ($paginator['current_page'] ?? 1);
    $last = (int) ($paginator['last_page'] ?? 1);
    $total = $paginator['total'] ?? null;
@endphp
@if ($last > 1 || $total)
    <div class="flex items-center justify-between gap-3 border-t border-slate-100 px-4 py-3 text-xs text-slate-500">
        <span>@if ($total !== null){{ number_format($total) }} {{ \Illuminate\Support\Str::plural('record', $total) }}@endif @if ($last > 1)· page {{ $current }} of {{ $last }}@endif</span>
        @if ($last > 1)
            <div class="flex gap-1">
                <button type="button" class="btn-light px-2.5 py-1 text-xs" wire:click="goToPage({{ $current - 1 }})" @disabled($current <= 1)>Previous</button>
                <button type="button" class="btn-light px-2.5 py-1 text-xs" wire:click="goToPage({{ $current + 1 }})" @disabled($current >= $last)>Next</button>
            </div>
        @endif
    </div>
@endif

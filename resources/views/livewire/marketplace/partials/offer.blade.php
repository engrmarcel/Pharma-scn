{{-- One marketplace offer (discovery shape). Needs $o; optional $showSupplier (default true), $canOrder, $canRelate. --}}
@php
    $showSupplier ??= true;
    $status = $o['availability']['status'] ?? null;
    $tone = ['in_stock' => 'text-emerald-700 bg-emerald-50', 'limited' => 'text-amber-800 bg-amber-50', 'out_of_stock' => 'text-rose-700 bg-rose-50'][$status] ?? 'text-slate-600 bg-slate-100';
    $types = collect($o['allowed_buyer_types'] ?? [])->map(fn ($t) => \App\Domain\Organizations\Enums\OrganizationType::tryFrom($t)?->label())->filter();
@endphp
<div class="card flex flex-col p-5" wire:key="o-{{ $o['listing_id'] }}">
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            <h3 class="font-semibold leading-snug text-navy-950">{{ $o['product']['display_name'] }}</h3>
            <p class="text-xs text-slate-500">{{ $o['pack']['name'] }}@if ($o['product']['generic_name']) · {{ $o['product']['generic_name'] }}@endif</p>
        </div>
        <p class="shrink-0 text-right text-lg font-bold tabular-nums text-navy-950">{{ fmt()->money($o['unit_price'], $o['currency_code']) }}
            <span class="block text-xs font-normal text-slate-500">per {{ strtolower($o['pack']['name']) }}</span>
            @if (($o['price_per_base_unit'] ?? null) !== null && (float) $o['pack']['base_unit_quantity'] > 1)<span class="block text-[11px] font-normal text-slate-400">{{ fmt()->money($o['price_per_base_unit'], $o['currency_code']) }} per {{ $o['pack']['base_unit'] ?? 'unit' }}</span>@endif
        </p>
    </div>

    @if ($showSupplier)
        <a href="{{ route('marketplace.storefront', $o['supplier']['id']) }}" class="mt-3 flex items-center gap-2 text-sm">
            <x-heroicon-o-building-storefront class="size-4 text-slate-400"/><span class="link">{{ $o['supplier']['name'] }}</span>
            <x-ui.badge :status="$o['supplier']['verification']['status']" title="{{ $o['supplier']['verification']['label'] }}">{{ str_replace('_', ' ', $o['supplier']['verification']['status']) }}</x-ui.badge>
        </a>
    @endif

    <div class="mt-3 flex flex-wrap items-center gap-1.5 text-xs">
        <span class="rounded-full px-2 py-0.5 font-medium {{ $tone }}" title="{{ $o['availability']['note'] ?? '' }}">{{ str_replace('_', ' ', ucfirst($status ?? 'unknown')) }}{{ ($o['availability']['source'] ?? null) === 'declared' ? ' (stated)' : '' }}{{ ($o['availability']['stale'] ?? false) ? ', not recently confirmed' : '' }}</span>
        <span class="rounded-full bg-slate-100 px-2 py-0.5 text-slate-600">Min. {{ fmt()->number($o['minimum_order_quantity'], 0) }} {{ strtolower($o['pack']['name']) }}</span>
        @if ($o['lead_time_days'] !== null)<span class="rounded-full bg-slate-100 px-2 py-0.5 text-slate-600">{{ $o['lead_time_days'] }}-day lead time</span>@endif
        @if ($o['restriction']['restricted'])<span class="rounded-full bg-amber-100 px-2 py-0.5 font-medium text-amber-800">{{ str_replace('_', ' ', $o['restriction']['classification']) }}</span>@endif
    </div>
    @if ($types->isNotEmpty())<p class="mt-2 text-xs text-slate-500">Sold to: {{ $types->implode(', ') }}</p>@endif
    @if (! $o['restriction']['you_may_buy'])<p class="mt-2 text-xs text-rose-700">{{ $o['restriction']['reason'] }}</p>@endif

    <div class="mt-auto flex flex-wrap items-center justify-end gap-2 pt-4">
        @if ($o['relationship'] !== 'approved')
            <span class="mr-auto text-xs text-slate-500">{{ $o['relationship'] ? 'Trading request: '.$o['relationship'] : 'No trading relationship yet' }}</span>
            @if (($canRelate ?? false) && ! $o['relationship'])<button type="button" class="btn-light btn-sm" wire:click="startRequest('{{ $o['supplier']['id'] }}', @js($o['supplier']['name']))">Request to trade</button>@endif
        @endif
        @if (($canOrder ?? false) && $o['restriction']['you_may_buy'])<button type="button" class="btn-primary btn-sm" wire:click="add(@js($o))"><x-heroicon-o-plus class="size-4"/>Add</button>@endif
    </div>
</div>

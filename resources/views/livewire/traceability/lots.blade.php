<div class="space-y-6">
    <x-ui.card>
        <div class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_14rem]">
            <x-ui.field label="Product">@include('livewire.partials.product-picker', ['target' => 'lot', 'selected' => $productName])</x-ui.field>
            <x-ui.field label="Batch number" for="batchNumber"><input id="batchNumber" wire:model.live.debounce.500ms="batchNumber" class="form-input font-mono uppercase"></x-ui.field>
        </div>
    </x-ui.card>

    @if ($lot)
        @if (! empty($lot['organizations']))
            <div class="flex flex-wrap gap-2">
                @foreach ($lot['organizations'] as $o)
                    <span class="rounded-full border border-slate-200 bg-white px-3 py-1 text-xs"><strong class="text-navy-900">{{ $o['name'] ?? '' }}</strong> · {{ implode(', ', (array) ($o['roles'] ?? [])) }}</span>
                @endforeach
            </div>
        @endif
        <ol class="relative space-y-4 border-l-2 border-navy-100 pl-6">
            @forelse ($lot['events'] as $e)
                <li class="relative">
                    <span class="absolute -left-[33px] top-1.5 size-4 rounded-full border-2 border-white {{ in_array($e['event_type'], ['recall', 'quarantine', 'quality_rejection', 'reversal'], true) ? 'bg-rose-500' : 'bg-[var(--brand-primary)]' }}"></span>
                    <div class="card p-4">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="font-semibold text-navy-950">{{ ucfirst(str_replace('_', ' ', $e['event_type'])) }}</span>
                            <span class="text-xs text-slate-500">{{ fmt()->dateTime($e['event_time']) }}</span>
                        </div>
                        <p class="mt-1 text-sm text-slate-600">
                            {{ $e['recorded_by_organization']['name'] }}
                            @if ($e['quantity'] !== null) · {{ fmt()->number($e['quantity'], 2) }} units @endif
                            @if (! empty($e['source'])) · from {{ $e['source']['name'] ?? $e['source']['location'] ?? '' }} @endif
                            @if (! empty($e['destination'])) · to {{ $e['destination']['name'] ?? $e['destination']['location'] ?? '' }} @endif
                        </p>
                        <p class="mt-1 text-xs text-slate-400">Verification: {{ str_replace('_', ' ', $e['verification']['state']) }}@if (count($e['superseded_by'])) · corrected later @endif</p>
                    </div>
                </li>
            @empty
                <li class="text-sm text-slate-500">No events you can see for this lot.</li>
            @endforelse
        </ol>
        <p class="text-xs text-slate-500">{{ $lot['note'] }}</p>
    @elseif ($productId && $batchNumber)
        <x-ui.card><x-ui.empty icon="magnifying-glass" title="No history for this lot"/></x-ui.card>
    @endif
</div>

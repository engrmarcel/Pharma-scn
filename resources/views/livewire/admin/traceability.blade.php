<div class="space-y-6">
    <x-ui.card title="Lot history across the network" icon="magnifying-glass">
        <div class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_14rem]">
            <x-ui.field label="Product">@include('livewire.partials.product-picker', ['target' => 'lot', 'selected' => $productName])</x-ui.field>
            <x-ui.field label="Batch number" for="batchNumber"><input id="batchNumber" wire:model.live.debounce.500ms="batchNumber" class="form-input font-mono uppercase"></x-ui.field>
        </div>
        @if ($lot)
            <div class="mt-4 flex flex-wrap gap-2">@foreach ($lot['organizations'] as $o)<span class="rounded-full border border-slate-200 px-3 py-1 text-xs"><strong>{{ $o['name'] }}</strong> · {{ implode(', ', $o['roles']) }}</span>@endforeach</div>
            <div class="table-wrap mt-4 rounded-lg border border-slate-200">
                <table class="data-table">
                    <thead><tr><th>When</th><th>Event</th><th>Recorded by</th><th class="num">Qty</th><th>From → to</th><th>Verification</th><th></th></tr></thead>
                    <tbody>
                        @forelse ($lot['events'] as $e)
                            <tr wire:key="e-{{ $e['id'] }}"><td class="whitespace-nowrap">{{ fmt()->dateTime($e['event_time']) }}</td><td>{{ str_replace('_', ' ', $e['event_type']) }}</td><td>{{ $e['recorded_by_organization']['name'] }}</td>
                                <td class="num">{{ $e['quantity'] !== null ? fmt()->number($e['quantity'], 2) : '—' }}</td>
                                <td class="text-xs">{{ $e['source']['name'] ?? ($e['source']['location'] ?? '') }} → {{ $e['destination']['name'] ?? ($e['destination']['location'] ?? ($e['counterparty_name'] ?? '')) }}</td>
                                <td><x-ui.badge :status="$e['verification']['state'] === 'disputed' ? 'disputed' : ($e['verification']['state'] === 'recorded' ? 'pending' : 'verified')">{{ str_replace('_', ' ', $e['verification']['state']) }}</x-ui.badge></td>
                                <td class="text-right">@if ($canVerify)<button type="button" class="link text-xs" wire:click="startVerify('{{ $e['id'] }}')">Verify</button>@endif</td></tr>
                        @empty
                            <x-ui.empty colspan="7" title="No events for this lot"/>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>

    <x-ui.card title="Chain integrity of an organization" icon="shield-check">
        <div class="flex flex-wrap items-end gap-2">
            <x-ui.field label="Organization" for="organization"><select id="organization" wire:model="organization" class="form-select w-72"><option value="">Choose…</option>@foreach ($organizations as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select></x-ui.field>
            <button type="button" class="btn-light" wire:click="checkIntegrity">Check</button>
        </div>
        @if ($integrity)<p class="mt-3 text-sm {{ $integrity['intact'] ? 'text-emerald-700' : 'text-rose-700' }}">{{ $integrity['intact'] ? 'Intact' : 'Broken at sequence '.$integrity['first_broken_sequence'] }} · {{ number_format($integrity['events']) }} events</p>@endif
    </x-ui.card>

    <x-ui.modal wire:model="verifying" title="Record an independent check" size="md">
        @if ($verifying)
            <form wire:submit="verify" id="ver-form" class="space-y-4">
                <x-ui.field label="Outcome" for="verifying.level"><select wire:model="verifying.level" class="form-select"><option value="independently_verified">Independently verified</option><option value="disputed">Disputed</option></select></x-ui.field>
                <x-ui.field label="Method" for="verifying.method"><select wire:model="verifying.method" class="form-select"><option value="document_review">Document review</option><option value="physical_inspection">Physical inspection</option><option value="regulator_confirmation">Regulator confirmation</option></select></x-ui.field>
                <x-ui.field label="Evidence" for="verifying.evidence"><textarea wire:model="verifying.evidence" rows="3" class="form-input" placeholder="What was seen, where and by whom"></textarea></x-ui.field>
            </form>
        @endif
        <x-slot:footer><button type="button" class="btn-light" @click="open = null">Cancel</button><button type="submit" form="ver-form" class="btn-primary">Record</button></x-slot:footer>
    </x-ui.modal>
</div>

<div>
    <x-ui.toolbar>
        <x-ui.field label="Event" for="type">
            <select id="type" wire:model.live="type" class="form-select w-56"><option value="">All events</option>@foreach ($types as $t)<option value="{{ $t }}">{{ str_replace('_', ' ', $t) }}</option>@endforeach</select>
        </x-ui.field>
        <x-ui.field label="From" for="from"><input id="from" type="date" wire:model.live="from" class="form-input w-40"></x-ui.field>
        <x-ui.field label="To" for="to"><input id="to" type="date" wire:model.live="to" class="form-input w-40"></x-ui.field>
    </x-ui.toolbar>

    <div class="card overflow-hidden">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>When</th><th>Event</th><th>Product</th><th>Batch</th><th class="num">Quantity</th><th>From</th><th>To</th><th>Verification</th></tr></thead>
                <tbody>
                    @forelse ($rows as $e)
                        <tr wire:key="e-{{ $e['id'] }}" class="cursor-pointer {{ count($e['superseded_by']) ? 'opacity-60' : '' }}" wire:click="open('{{ $e['id'] }}')">
                            <td class="whitespace-nowrap">{{ fmt()->dateTime($e['event_time']) }}</td>
                            <td><x-ui.badge :tone="in_array($e['event_type'], ['correction', 'reversal', 'recall', 'quarantine', 'quality_rejection'], true) ? 'amber' : 'blue'">{{ str_replace('_', ' ', $e['event_type']) }}</x-ui.badge></td>
                            <td class="font-medium text-navy-900">{{ $names[$e['product_id']] ?? '—' }}</td>
                            <td class="font-mono text-xs">{{ $e['batch_number'] ?? '—' }}{{ $e['serial'] ? ' · '.$e['serial'] : '' }}</td>
                            <td class="num">{{ $e['quantity'] !== null ? fmt()->number($e['quantity'], 2) : '—' }}</td>
                            <td class="text-xs">{{ $e['source']['name'] ?? ($e['source']['location'] ?? '—') }}</td>
                            <td class="text-xs">{{ $e['destination']['name'] ?? ($e['destination']['location'] ?? ($e['counterparty_name'] ?? '—')) }}</td>
                            <td><x-ui.badge :status="$e['verification']['state'] === 'disputed' ? 'disputed' : ($e['verification']['state'] === 'recorded' ? 'pending' : 'verified')">{{ str_replace('_', ' ', $e['verification']['state']) }}</x-ui.badge></td>
                        </tr>
                    @empty
                        <x-ui.empty colspan="8" icon="list-bullet" title="No events"/>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-ui.pager :paginator="$meta"/>
    </div>

    <x-ui.modal wire:model="openId" title="Trace event" size="lg">
        @if ($detail)
            <x-ui.dl :cols="3" :items="[
                'Event' => str_replace('_', ' ', $detail['event_type']), 'When' => fmt()->dateTime($detail['event_time']), 'Recorded' => fmt()->dateTime($detail['recorded_at']),
                'Recorded by' => $detail['recorded_by_organization']['name'], 'Product' => $names[$detail['product_id']] ?? null, 'Batch' => $detail['batch_number'],
                'Serial' => $detail['serial'], 'Quantity' => $detail['quantity'], 'Source system' => $detail['source_system'],
                'From' => $detail['source']['name'] ?? ($detail['source']['location'] ?? null), 'To' => $detail['destination']['name'] ?? ($detail['destination']['location'] ?? null),
                'Counterparty' => $detail['counterparty_name'],
            ]"/>
            @if ($detail['correction_reason'])<p class="mt-3 text-sm text-slate-600"><strong>Reason:</strong> {{ $detail['correction_reason'] }}</p>@endif
            @if ($detail['corrects_event_id'] || $detail['reverses_event_id'])
                <button type="button" class="link mt-2 text-sm" wire:click="open('{{ $detail['corrects_event_id'] ?? $detail['reverses_event_id'] }}')">Open the original event</button>
            @endif
            @if (count($detail['superseded_by']))
                <p class="mt-3 text-sm text-amber-800">This event has been corrected or reversed.
                    @foreach ($detail['superseded_by'] as $sid)<button type="button" class="link ml-1" wire:click="open('{{ $sid }}')">View</button>@endforeach</p>
            @endif
            @if (! empty($detail['details']))
                <pre class="mt-4 max-h-48 overflow-auto rounded-lg bg-slate-50 p-3 text-xs">{{ json_encode($detail['details'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
            @endif

            @if ($amend)
                <form wire:submit="saveAmend(@js($detail))" class="mt-4 space-y-3 rounded-lg border border-slate-200 bg-slate-50 p-4">
                    @if ($amend['mode'] === 'correct')
                        <div class="grid gap-3 sm:grid-cols-2">
                            <x-ui.field label="Quantity" for="amend.changes.quantity"><input wire:model="amend.quantity" inputmode="decimal" class="form-input py-2"></x-ui.field>
                            <x-ui.field label="Batch number" for="amend.changes.batch_number"><input wire:model="amend.batch_number" class="form-input py-2 font-mono uppercase"></x-ui.field>
                            <x-ui.field label="Event time" for="amend.changes.event_time"><input type="datetime-local" wire:model="amend.event_time" class="form-input py-2"></x-ui.field>
                            <x-ui.field label="Counterparty" for="amend.changes.counterparty_name"><input wire:model="amend.counterparty_name" class="form-input py-2"></x-ui.field>
                        </div>
                        @error('amend.changes') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
                    @endif
                    <x-ui.field label="Reason" for="amend.reason"><textarea wire:model="amend.reason" rows="2" class="form-input"></textarea></x-ui.field>
                    <div class="flex justify-end gap-2"><button type="button" class="btn-light btn-sm" wire:click="$set('amend', null)">Back</button>
                        <button class="{{ $amend['mode'] === 'reverse' ? 'btn-danger' : 'btn-primary' }} btn-sm">{{ $amend['mode'] === 'reverse' ? 'Record reversal' : 'Record correction' }}</button></div>
                </form>
            @endif
        @endif
        <x-slot:footer>
            @if ($detail && $canManage && ! $amend && ! count($detail['superseded_by']) && ! in_array($detail['event_type'], ['correction', 'reversal'], true)
                && $detail['recorded_by_organization']['id'] === app(\App\Support\Tenancy\TenantContext::class)->id())
                <button type="button" class="btn-light" wire:click="startAmend('reverse', @js($detail))">Reverse</button>
                <button type="button" class="btn-primary" wire:click="startAmend('correct', @js($detail))">Correct</button>
            @endif
        </x-slot:footer>
    </x-ui.modal>
</div>

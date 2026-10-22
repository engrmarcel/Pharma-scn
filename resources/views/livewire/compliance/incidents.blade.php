<div>
    <x-ui.toolbar>
        <x-ui.field label="Status" for="status">
            <select id="status" wire:model.live="status" class="form-select w-40"><option value="">All</option><option value="open">Open</option><option value="reported">Reported</option><option value="closed">Closed</option></select>
        </x-ui.field>
        <label class="flex items-center gap-2 pb-3 text-sm"><input type="checkbox" wire:model.live="overdue" class="form-check">Overdue only</label>
        <x-slot:actions><button type="button" class="btn-primary" wire:click="create"><x-heroicon-o-plus class="size-4"/>Record incident</button></x-slot:actions>
    </x-ui.toolbar>

    <div class="card overflow-hidden">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Number</th><th>Type</th><th>Product</th><th>Batch</th><th>Detected</th><th>Report due</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    @forelse ($rows as $i)
                        <tr wire:key="in-{{ $i['id'] }}">
                            <td class="font-mono text-xs font-semibold">{{ $i['number'] }}</td>
                            <td>{{ str_replace('_', ' ', $i['type']) }}<span class="muted block max-w-xs truncate">{{ $i['description'] }}</span></td>
                            <td>{{ $names[$i['product_id']] ?? '—' }}</td>
                            <td class="font-mono text-xs">{{ $i['batch_number'] ?? '—' }}</td>
                            <td>{{ fmt()->dateTime($i['detected_at']) }}</td>
                            <td class="{{ $i['overdue'] ? 'font-semibold text-rose-700' : '' }}">{{ $i['report_due_at'] ? fmt()->dateTime($i['report_due_at']) : 'No deadline' }}@if ($i['reported_late'])<span class="muted block">reported late</span>@endif</td>
                            <td><x-ui.badge :status="$i['overdue'] ? 'overdue' : $i['status']">{{ $i['overdue'] ? 'overdue' : $i['status'] }}</x-ui.badge>@if ($i['authority_reference'])<span class="muted block">{{ $i['authority_reference'] }}</span>@endif</td>
                            <td class="whitespace-nowrap text-right">
                                @if ($i['status'] === 'open')<button type="button" class="link text-xs" wire:click="start('{{ $i['id'] }}', 'report')">Reported</button>@endif
                                @if ($i['status'] !== 'closed')<button type="button" class="link ml-2 text-xs" wire:click="start('{{ $i['id'] }}', 'close')">Close</button>@endif
                            </td>
                        </tr>
                    @empty
                        <x-ui.empty colspan="8" icon="document-magnifying-glass" title="No incidents"/>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <x-ui.modal wire:model="acting" :title="($acting['action'] ?? '') === 'report' ? 'Record the regulator report' : 'Close the incident'" size="sm">
        @if ($acting)
            <form wire:submit="apply" id="act-form">
                @if ($acting['action'] === 'report')
                    <x-ui.field label="Regulator's reference" for="acting.authority_reference"><input id="acting.authority_reference" wire:model="acting.authority_reference" class="form-input"></x-ui.field>
                @else
                    <x-ui.field label="Closing notes" for="acting.notes"><textarea id="acting.notes" wire:model="acting.notes" rows="3" class="form-input"></textarea></x-ui.field>
                @endif
            </form>
        @endif
        <x-slot:footer>
            <button type="button" class="btn-light" @click="open = null">Cancel</button>
            <button type="submit" form="act-form" class="btn-primary">Save</button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal wire:model="form" title="Record a regulatory incident" size="lg">
        @if ($form)
            <form wire:submit="save" id="inc-form" class="grid gap-4 sm:grid-cols-2">
                <x-ui.field label="Type" for="form.type"><select id="form.type" wire:model="form.type" class="form-select">@foreach ($types as $t)<option value="{{ $t }}">{{ ucfirst(str_replace('_', ' ', $t)) }}</option>@endforeach</select></x-ui.field>
                <x-ui.field label="Detected" for="form.detected_at"><input id="form.detected_at" type="datetime-local" wire:model="form.detected_at" class="form-input"></x-ui.field>
                <x-ui.field label="Product (optional)" class="sm:col-span-2">@include('livewire.partials.product-picker', ['target' => 'incident', 'selected' => $form['product_name']])</x-ui.field>
                <x-ui.field label="Batch" for="form.batch_number"><input id="form.batch_number" wire:model="form.batch_number" class="form-input font-mono uppercase"></x-ui.field>
                <x-ui.field label="Quantity" for="form.quantity"><input id="form.quantity" wire:model="form.quantity" inputmode="decimal" class="form-input"></x-ui.field>
                <x-ui.field label="What happened" for="form.description" class="sm:col-span-2"><textarea id="form.description" wire:model="form.description" rows="3" class="form-input"></textarea></x-ui.field>
            </form>
            <p class="mt-3 text-xs text-slate-500">The reporting deadline comes from your country's rules for this type of incident.</p>
        @endif
        <x-slot:footer>
            <button type="button" class="btn-light" @click="open = null">Cancel</button>
            <button type="submit" form="inc-form" class="btn-primary">Record</button>
        </x-slot:footer>
    </x-ui.modal>
</div>

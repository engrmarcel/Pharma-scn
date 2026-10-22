<div>
    <x-ui.toolbar>
        <x-ui.field label="Status" for="status">
            <select id="status" wire:model.live="status" class="form-select w-48"><option value="">All</option>
                @foreach (['planned', 'dispatched', 'in_transit', 'delivered', 'partially_delivered', 'failed', 'returned_to_origin', 'cancelled'] as $s)<option value="{{ $s }}">{{ ucfirst(str_replace('_', ' ', $s)) }}</option>@endforeach
            </select>
        </x-ui.field>
        <x-ui.field label="Our role" for="role"><select id="role" wire:model.live="role" class="form-select w-40"><option value="">Any</option><option value="shipper">Shipper</option><option value="carrier">Carrier</option></select></x-ui.field>
        <x-slot:actions>@if ($can['manage'])<button type="button" class="btn-primary" wire:click="create"><x-heroicon-o-plus class="size-4"/>Plan a shipment</button>@endif</x-slot:actions>
    </x-ui.toolbar>

    <div class="card overflow-hidden">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Shipment</th><th>To</th><th>Carrier · driver</th><th>Planned</th><th>Cold chain</th><th>Status</th></tr></thead>
                <tbody>
                    @forelse ($rows as $s)
                        <tr wire:key="s-{{ $s['id'] }}" class="cursor-pointer" wire:click="$set('openId', '{{ $s['id'] }}')">
                            <td><span class="font-mono text-xs font-semibold">{{ $s['number'] }}</span><span class="muted block">{{ str_replace('_', ' ', $s['source_type']) }} · {{ $s['your_role'] }}</span></td>
                            <td class="font-medium text-navy-900">{{ $s['destination']['name'] ?? '' }}</td>
                            <td class="text-xs">{{ $s['carrier']['name'] ?? 'Own fleet' }}{{ $s['driver']['name'] ? ' · '.$s['driver']['name'] : '' }}{{ $s['vehicle']['registration'] ? ' · '.$s['vehicle']['registration'] : '' }}</td>
                            <td>{{ $s['planned_delivery_at'] ? fmt()->dateTime($s['planned_delivery_at']) : '—' }}</td>
                            <td>@if ($s['cold_chain'])<x-ui.badge tone="blue">{{ $s['cold_chain']['min_c'] }}–{{ $s['cold_chain']['max_c'] }} °C</x-ui.badge>@else — @endif</td>
                            <td><x-ui.badge :status="$s['status']"/></td>
                        </tr>
                    @empty
                        <x-ui.empty colspan="6" icon="truck" title="No shipments"/>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <x-ui.modal wire:model="openId" :title="'Shipment '.($detail['number'] ?? '')" size="xl">
        @if ($detail)
            @php $st = $detail['status']; @endphp
            <x-ui.dl :cols="3" :items="[
                'Status' => str_replace('_', ' ', $st), 'From' => $warehouses[$detail['origin_warehouse_id']] ?? null, 'To' => trim(($detail['destination']['name'] ?? '').' '.($detail['destination']['address'] ?? '')),
                'Carrier' => $detail['carrier']['name'] ?? 'Own fleet', 'Driver' => trim(($detail['driver']['name'] ?? '').' '.($detail['driver']['phone'] ?? '')), 'Vehicle' => $detail['vehicle']['registration'],
                'Planned delivery' => $detail['planned_delivery_at'] ? fmt()->dateTime($detail['planned_delivery_at']) : null, 'Dispatched' => $detail['dispatched_at'] ? fmt()->dateTime($detail['dispatched_at']) : null,
                'Delivered' => $detail['delivered_at'] ? fmt()->dateTime($detail['delivered_at']) : null,
            ]"/>
            <div class="table-wrap mt-4 rounded-lg border border-slate-200">
                <table class="data-table">
                    <thead><tr><th>Product</th><th>Batch</th><th>Expiry</th><th class="num">Shipped</th><th class="num">Delivered</th><th class="num">Returned</th></tr></thead>
                    <tbody>
                        @foreach ($detail['items'] as $i)
                            <tr><td>{{ $names[$i['product_id']] ?? '' }}</td><td class="font-mono text-xs">{{ $i['batch_number'] }}</td><td>{{ $i['expiry_date'] ? fmt()->date($i['expiry_date']) : '—' }}</td>
                                <td class="num">{{ fmt()->number($i['quantity'], 2) }}</td><td class="num">{{ $i['delivered_quantity'] !== null ? fmt()->number($i['delivered_quantity'], 2) : '—' }}</td><td class="num">{{ $i['returned_quantity'] !== null ? fmt()->number($i['returned_quantity'], 2) : '—' }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($detail['proof_of_delivery'])
                @php $pod = $detail['proof_of_delivery']; @endphp
                <p class="mt-3 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-900">Received by {{ $pod['received_by_name'] }}{{ $pod['received_by_role'] ? ' ('.$pod['received_by_role'].')' : '' }} on {{ fmt()->dateTime($pod['received_at']) }} · condition {{ str_replace('_', ' ', $pod['condition']) }}{{ $pod['signature_attachment_id'] ? ' · signed' : '' }}{{ $pod['notes'] ? ' · '.$pod['notes'] : '' }}</p>
            @endif
            @foreach ($detail['next_steps'] as $n)<p class="mt-2 rounded-lg bg-amber-50 p-3 text-sm text-amber-900">{{ $n }}</p>@endforeach

            @if ($temps)
                <h3 class="mb-2 mt-5 text-sm font-semibold text-navy-950">Temperatures (required {{ $temps['required']['min_c'] ?? '' }}–{{ $temps['required']['max_c'] ?? '' }} °C)</h3>
                @if (count($temps['readings']))
                    <div class="flex h-20 items-end gap-px rounded-lg border border-slate-200 bg-slate-50 p-2" aria-label="Temperature readings">
                        @php $lo = min(collect($temps['readings'])->min('temperature_c'), $temps['required']['min_c'] ?? 0) - 1; $hi = max(collect($temps['readings'])->max('temperature_c'), $temps['required']['max_c'] ?? 8) + 1; @endphp
                        @foreach (collect($temps['readings'])->take(-120) as $r)
                            @php $out = $temps['required'] && ($r['temperature_c'] < $temps['required']['min_c'] || $r['temperature_c'] > $temps['required']['max_c']); @endphp
                            <span class="w-1.5 flex-1 rounded-t {{ $out ? 'bg-rose-500' : 'bg-sky-500' }}" style="height: {{ max(4, ($r['temperature_c'] - $lo) / max(0.1, $hi - $lo) * 100) }}%" title="{{ $r['temperature_c'] }} °C at {{ $r['recorded_at'] }}"></span>
                        @endforeach
                    </div>
                @else
                    <p class="text-sm text-slate-500">No readings yet.</p>
                @endif
                @foreach ($temps['excursions'] as $e)
                    <div class="mt-2 flex flex-wrap items-center justify-between gap-2 rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-900">
                        <span>Excursion {{ fmt()->dateTime($e['started_at']) }} · {{ $e['minutes'] }} min · {{ $e['lowest_c'] }} to {{ $e['highest_c'] }} °C · {{ str_replace('_', ' ', $e['status']) }}{{ $e['assessment'] ? ': '.str_replace('_', ' ', $e['assessment']) : '' }}</span>
                        @if ($e['status'] === 'open')<button type="button" class="link text-xs" wire:click="startStep('assess', @js(['excursion_id' => $e['id']]))">Assess</button>@endif
                    </div>
                @endforeach
            @endif

            @if (! empty($detail['events']))
                <p class="mb-1 mt-4 text-xs font-semibold uppercase text-slate-400">Tracking</p>
                <ul class="text-sm text-slate-600">@foreach ($detail['events'] as $e)<li>{{ fmt()->dateTime($e['occurred_at']) }} · {{ str_replace('_', ' ', $e['event_type']) }}{{ $e['location_text'] ? ' · '.$e['location_text'] : '' }}{{ $e['notes'] ? ' · '.$e['notes'] : '' }} <span class="text-xs text-slate-400">({{ $e['recorded_by'] }})</span></li>@endforeach</ul>
            @endif

            @if ($step)
                <form wire:submit="applyStep" class="mt-5 space-y-3 rounded-lg border border-slate-200 bg-slate-50 p-4">
                    @switch($step['type'])
                        @case('assign')
                            <div class="grid gap-3 sm:grid-cols-3">
                                <x-ui.field label="Carrier" for="step.carrier_name"><input wire:model="step.carrier_name" class="form-input py-2" placeholder="Own fleet"></x-ui.field>
                                <x-ui.field label="Driver" for="step.driver_name"><input wire:model="step.driver_name" class="form-input py-2"></x-ui.field>
                                <x-ui.field label="Driver phone" for="step.driver_phone"><input wire:model="step.driver_phone" class="form-input py-2"></x-ui.field>
                                <x-ui.field label="Vehicle" for="step.vehicle_id"><select wire:model="step.vehicle_id" class="form-select py-2"><option value="">—</option>@foreach ($vehicles as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select></x-ui.field>
                                <x-ui.field label="Temperature logger" for="step.temperature_logger_id"><input wire:model="step.temperature_logger_id" class="form-input py-2"></x-ui.field>
                                <x-ui.field label="Delivery planned" for="step.planned_delivery_at"><input type="datetime-local" wire:model="step.planned_delivery_at" class="form-input py-2"></x-ui.field>
                            </div>
                            @break
                        @case('track')
                            <div class="grid gap-3 sm:grid-cols-3">
                                <x-ui.field label="Event" for="step.event_type"><select wire:model="step.event_type" class="form-select py-2"><option value="location_update">Location update</option><option value="arrived">Arrived</option><option value="delivery_attempted">Delivery attempted</option><option value="note">Note</option></select></x-ui.field>
                                <x-ui.field label="Where" for="step.location_text"><input wire:model="step.location_text" class="form-input py-2"></x-ui.field>
                                <x-ui.field label="Notes" for="step.notes"><input wire:model="step.notes" class="form-input py-2"></x-ui.field>
                            </div>
                            @break
                        @case('temperature')
                            @error('step.readings') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
                            @foreach ($step['readings'] as $i => $r)
                                <div class="grid grid-cols-2 gap-2"><input type="datetime-local" wire:model="step.readings.{{ $i }}.recorded_at" class="form-input py-2" aria-label="When"><input wire:model="step.readings.{{ $i }}.temperature_c" inputmode="decimal" class="form-input py-2" placeholder="°C" aria-label="Temperature"></div>
                            @endforeach
                            <button type="button" class="link text-sm" wire:click="addReading">+ Another reading</button>
                            @break
                        @case('deliver')
                            <div class="grid gap-3 sm:grid-cols-3">
                                <x-ui.field label="Received by" for="step.received_by_name"><input wire:model="step.received_by_name" class="form-input py-2"></x-ui.field>
                                <x-ui.field label="Their role" for="step.received_by_role"><input wire:model="step.received_by_role" class="form-input py-2" placeholder="Pharmacist"></x-ui.field>
                                <x-ui.field label="Condition" for="step.condition"><select wire:model="step.condition" class="form-select py-2"><option value="good">Good</option><option value="damaged">Damaged</option><option value="temperature_concern">Temperature concern</option></select></x-ui.field>
                            </div>
                            @foreach ($detail['items'] as $i)
                                <div class="flex items-center justify-between gap-2 text-sm"><span>{{ $names[$i['product_id']] ?? '' }} · {{ $i['batch_number'] }}</span><input wire:model="step.items.{{ $i['id'] }}" inputmode="decimal" class="form-input w-24 py-1.5 text-right" aria-label="Delivered"></div>
                            @endforeach
                            <x-ui.field label="Signature image (optional)" for="step.signature"><input type="file" wire:model="signature" accept="image/png,image/jpeg" class="form-input py-1.5"></x-ui.field>
                            <x-ui.field label="Notes" for="step.notes"><input wire:model="step.notes" class="form-input py-2"></x-ui.field>
                            @break
                        @case('return')
                            @foreach ($detail['items'] as $i)
                                <div class="flex items-center justify-between gap-2 text-sm"><span>{{ $names[$i['product_id']] ?? '' }} · {{ $i['batch_number'] }}</span><input wire:model="step.items.{{ $i['id'] }}" inputmode="decimal" class="form-input w-24 py-1.5 text-right" aria-label="Returned"></div>
                            @endforeach
                            <x-ui.field label="Notes" for="step.notes"><input wire:model="step.notes" class="form-input py-2"></x-ui.field>
                            @break
                        @case('assess')
                            <x-ui.field label="Decision" for="step.assessment"><select wire:model="step.assessment" class="form-select py-2">@foreach ($assessments as $a)<option value="{{ $a }}">{{ ucfirst(str_replace('_', ' ', $a)) }}</option>@endforeach</select></x-ui.field>
                            <x-ui.field label="Reasoning" for="step.notes"><textarea wire:model="step.notes" rows="2" class="form-input"></textarea></x-ui.field>
                            @break
                        @default
                            <x-ui.field label="Reason" for="step.reason"><textarea wire:model="step.reason" rows="2" class="form-input"></textarea></x-ui.field>
                    @endswitch
                    <div class="flex justify-end gap-2"><button type="button" class="btn-light btn-sm" wire:click="$set('step', null)">Back</button><button class="btn-primary btn-sm" wire:loading.attr="disabled">Save</button></div>
                </form>
            @endif
        @endif
        <x-slot:footer>
            @if ($detail && ! $step)
                @if ($st === 'planned' && $can['manage'])
                    <button type="button" class="btn-light" wire:click="startStep('cancel')">Cancel</button>
                    <button type="button" class="btn-light" wire:click="startStep('assign', @js($detail))">Assign</button>
                    <button type="button" class="btn-primary" wire:click="dispatchShipment">Dispatch</button>
                @endif
                @if (in_array($st, ['dispatched', 'in_transit'], true) && $can['deliver'])
                    <button type="button" class="btn-light" wire:click="startStep('track')">Tracking event</button>
                    @if ($detail['cold_chain'])<button type="button" class="btn-light" wire:click="startStep('temperature')">Temperatures</button>@endif
                    <button type="button" class="btn-danger" wire:click="startStep('fail')">Failed</button>
                    <button type="button" class="btn-primary" wire:click="startStep('deliver', @js($detail))">Deliver</button>
                @endif
                @if (in_array($st, ['failed', 'partially_delivered'], true) && $can['deliver'])<button type="button" class="btn-light" wire:click="startStep('return', @js($detail))">Return to origin</button>@endif
            @endif
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal wire:model="form" title="Plan a shipment" size="lg">
        @if ($form)
            <form wire:submit="save" id="shp-form" class="grid gap-4 sm:grid-cols-2">
                <x-ui.field label="Shipping" for="form.source_type"><select id="form.source_type" wire:model.live="form.source_type" class="form-select"><option value="sales_delivery">A sales delivery</option><option value="stock_transfer">A stock transfer</option><option value="manual">Something else</option></select></x-ui.field>
                @if ($form['source_type'] !== 'manual')
                    <x-ui.field :label="$form['source_type'] === 'stock_transfer' ? 'Dispatched transfer' : 'Sales delivery'" for="form.source_id"><select id="form.source_id" wire:model="form.source_id" class="form-select"><option value="">Choose…</option>@foreach ($deliveries as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select></x-ui.field>
                @else
                    <x-ui.field label="From warehouse" for="form.origin_warehouse_id"><select id="form.origin_warehouse_id" wire:model="form.origin_warehouse_id" class="form-select"><option value="">Choose…</option>@foreach ($warehouses as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select></x-ui.field>
                    <x-ui.field label="Deliver to" for="form.destination_name"><input id="form.destination_name" wire:model="form.destination_name" class="form-input"></x-ui.field>
                    <x-ui.field label="Address" for="form.destination_address"><input id="form.destination_address" wire:model="form.destination_address" class="form-input"></x-ui.field>
                    <div class="sm:col-span-2 space-y-2">
                        @error('form.items') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
                        @foreach ($form['items'] as $i => $it)
                            <div class="grid gap-2 sm:grid-cols-[1fr_9rem_7rem]" wire:key="si-{{ $i }}">
                                @include('livewire.partials.product-picker', ['target' => 'item.'.$i, 'selected' => $it['name']])
                                <input wire:model="form.items.{{ $i }}.batch_number" class="form-input py-2 font-mono uppercase" placeholder="Batch">
                                <input wire:model="form.items.{{ $i }}.quantity" inputmode="decimal" class="form-input py-2" placeholder="Qty">
                            </div>
                        @endforeach
                        <button type="button" class="link text-sm" wire:click="addItem">+ Add item</button>
                    </div>
                @endif
                <x-ui.field label="Delivery planned" for="form.planned_delivery_at"><input id="form.planned_delivery_at" type="datetime-local" wire:model="form.planned_delivery_at" class="form-input"></x-ui.field>
                <x-ui.field label="Notes" for="form.notes"><input id="form.notes" wire:model="form.notes" class="form-input"></x-ui.field>
            </form>
        @endif
        <x-slot:footer><button type="button" class="btn-light" @click="open = null">Cancel</button><button type="submit" form="shp-form" class="btn-primary">Plan shipment</button></x-slot:footer>
    </x-ui.modal>
</div>

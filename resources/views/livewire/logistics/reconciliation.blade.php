<div>
    <x-ui.toolbar>
        <x-ui.field label="From" for="from"><input id="from" type="date" wire:model.live="from" class="form-input w-44"></x-ui.field>
        <x-ui.field label="To" for="to"><input id="to" type="date" wire:model.live="to" class="form-input w-44"></x-ui.field>
    </x-ui.toolbar>
    @if ($data)
        <div class="grid gap-4 sm:grid-cols-3 lg:grid-cols-6">
            <x-ui.stat label="Shipped" :value="fmt()->number($data['quantities']['shipped'], 0)"/>
            <x-ui.stat label="Delivered" :value="fmt()->number($data['quantities']['delivered'], 0)" tone="green"/>
            <x-ui.stat label="Returned" :value="fmt()->number($data['quantities']['returned'], 0)" tone="amber"/>
            <x-ui.stat label="On time" :value="$data['on_time_percent'] !== null ? $data['on_time_percent'].'%' : '—'" tone="gold"/>
            <x-ui.stat label="Cold-chain excursions" :value="$data['cold_chain']['excursions']" :hint="$data['cold_chain']['unassessed'].' not yet assessed'" tone="red"/>
            <x-ui.stat label="Failed" :value="count($data['failed'])" tone="red"/>
        </div>
        <div class="mt-6 grid gap-6 lg:grid-cols-2">
            <x-ui.card title="Shipments by status" icon="truck">
                <ul class="space-y-1 text-sm">@forelse ($data['shipments'] as $s => $n)<li class="flex justify-between"><span>{{ str_replace('_', ' ', $s) }}</span><span class="tabular-nums">{{ $n }}</span></li>@empty<li class="text-slate-500">No shipments in this period.</li>@endforelse</ul>
            </x-ui.card>
            <x-ui.card title="Quantities not accounted for" icon="exclamation-triangle">
                <ul class="space-y-1 text-sm">
                    @forelse ($data['discrepancies'] as $d)
                        <li class="flex justify-between"><a href="{{ route('logistics.shipments', ['open' => $d['shipment_id']]) }}" class="link font-mono text-xs">{{ $d['number'] }}</a><span>{{ str_replace('_', ' ', $d['status']) }} · <strong class="text-rose-700">{{ fmt()->number($d['unaccounted'], 2) }}</strong></span></li>
                    @empty
                        <li class="text-slate-500">Everything shipped was delivered or returned.</li>
                    @endforelse
                </ul>
            </x-ui.card>
        </div>
    @else
        <x-ui.card><x-ui.empty icon="scale" title="No figures"/></x-ui.card>
    @endif
</div>

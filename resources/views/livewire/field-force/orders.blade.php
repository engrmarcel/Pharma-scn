<div>
    <x-ui.toolbar>
        <x-ui.field label="Status" for="status">
            <select id="status" wire:model.live="status" class="form-select w-40"><option value="">All</option>@foreach (['draft', 'submitted', 'accepted', 'rejected', 'cancelled'] as $s)<option value="{{ $s }}">{{ ucfirst($s) }}</option>@endforeach</select>
        </x-ui.field>
        <x-slot:actions>@if ($canCapture)<button type="button" class="btn-primary" wire:click="create"><x-heroicon-o-plus class="size-4"/>Capture order</button>@endif</x-slot:actions>
    </x-ui.toolbar>

    <div class="card overflow-hidden">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Order</th><th>Customer</th><th>Representative</th><th>Captured</th><th class="num">Lines</th><th>Sales order</th><th>Status</th></tr></thead>
                <tbody>
                    @forelse ($rows as $o)
                        <tr wire:key="fo-{{ $o['id'] }}" class="cursor-pointer" wire:click="$set('openId', '{{ $o['id'] }}')">
                            <td class="font-mono text-xs font-semibold">{{ $o['number'] }}</td>
                            <td class="font-medium text-navy-900">{{ $customers[$o['customer_id']] ?? '' }}</td>
                            <td>{{ $people[$o['representative_id']] ?? '' }}</td>
                            <td>{{ fmt()->dateTime($o['captured_at']) }}@if ($o['captured_offline'] ?? false)<span class="muted block">offline</span>@endif</td>
                            <td class="num">{{ count($o['lines'] ?? []) }}</td>
                            <td class="font-mono text-xs">{{ $o['sales_order']['number'] ?? '—' }}</td>
                            <td><x-ui.badge :status="$o['status']"/></td>
                        </tr>
                    @empty
                        <x-ui.empty colspan="7" icon="shopping-bag" title="No field orders"/>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-ui.pager :paginator="$meta"/>
    </div>

    <x-ui.modal wire:model="openId" :title="'Field order '.($detail['number'] ?? '')" size="lg">
        @if ($detail)
            <div class="mb-3 flex flex-wrap items-center gap-2 text-sm"><x-ui.badge :status="$detail['status']"/><span class="font-medium">{{ $customers[$detail['customer_id']] ?? '' }}</span>
                @if ($detail['sales_order'])<a href="{{ route('sales.orders', ['open' => $detail['sales_order']['id']]) }}" class="link">Sales order {{ $detail['sales_order']['number'] }} · {{ $detail['sales_order']['status'] }}</a>@endif
                @if ($detail['stock_reserved'])<x-ui.badge tone="green">stock reserved</x-ui.badge>@endif</div>
            @if ($detail['rejection_reason'] ?? null)<p class="mb-3 rounded-lg bg-rose-50 p-3 text-sm text-rose-800">Rejected: {{ $detail['rejection_reason'] }}</p>@endif
            <ul class="divide-y divide-slate-100 rounded-lg border border-slate-200 text-sm">
                @foreach ($detail['lines'] as $l)
                    <li class="flex justify-between gap-3 px-3 py-2"><span>{{ $names[$l['product_id']] ?? '' }} · {{ $l['package_level_code'] }}</span>
                        <span class="tabular-nums">{{ fmt()->number($l['quantity'], 2) }}@if ($l['indicative_unit_price']) × {{ fmt()->number($l['indicative_unit_price'], 2) }}@endif</span></li>
                @endforeach
            </ul>
            @if ($detail['notes'])<p class="mt-3 text-sm text-slate-600">{{ $detail['notes'] }}</p>@endif
        @endif
        <x-slot:footer>
            @if ($detail && $canCapture && $detail['status'] === 'draft')
                <button type="button" class="btn-danger" wire:click="cancelOrder">Cancel</button>
                <button type="button" class="btn-primary" wire:click="submitOrder">Submit</button>
            @endif
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal wire:model="doc" title="Capture an order" size="xl">
        @if ($doc)
            <form wire:submit="save" id="fo-form" class="space-y-5">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <x-ui.field label="Customer" for="doc.customer_id"><select id="doc.customer_id" wire:model="doc.customer_id" class="form-select"><option value="">Choose…</option>@foreach ($customers as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select></x-ui.field>
                    <x-ui.field label="Supply from" for="doc.warehouse_id" hint="Empty: the territory's warehouse."><select id="doc.warehouse_id" wire:model="doc.warehouse_id" class="form-select"><option value="">Territory default</option>@foreach ($warehouses as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select></x-ui.field>
                    <x-ui.field label="Requested delivery" for="doc.requested_date"><input id="doc.requested_date" type="date" wire:model="doc.requested_date" class="form-input"></x-ui.field>
                    <x-ui.field label="Notes" for="doc.notes"><input id="doc.notes" wire:model="doc.notes" class="form-input"></x-ui.field>
                </div>
                @include('livewire.partials.document-lines', ['form' => 'doc', 'showTax' => false, 'priceLabel' => 'Indicative price'])
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="doc.submit" class="form-check">Submit to sales straight away</label>
            </form>
        @endif
        <x-slot:footer><button type="button" class="btn-light" @click="open = null">Cancel</button><button type="submit" form="fo-form" class="btn-primary">Save</button></x-slot:footer>
    </x-ui.modal>
</div>

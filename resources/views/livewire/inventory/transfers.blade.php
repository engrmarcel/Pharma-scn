<div>
    <x-ui.toolbar>
        <x-ui.field label="Status" for="status">
            <select id="status" wire:model.live="status" class="form-select w-48">
                <option value="">All</option>@foreach (['draft', 'dispatched', 'partially_received', 'received', 'cancelled'] as $s)<option value="{{ $s }}">{{ ucfirst(str_replace('_', ' ', $s)) }}</option>@endforeach
            </select>
        </x-ui.field>
        <x-slot:actions>
            @if ($canTransfer)<button type="button" class="btn-primary" wire:click="create"><x-heroicon-o-plus class="size-4"/>New transfer</button>@endif
        </x-slot:actions>
    </x-ui.toolbar>

    <div class="card overflow-hidden">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Number</th><th>From</th><th>To</th><th class="num">Lines</th><th>Created</th><th>Status</th></tr></thead>
                <tbody>
                    @forelse ($rows as $t)
                        <tr wire:key="t-{{ $t['id'] }}" class="cursor-pointer" wire:click="open('{{ $t['id'] }}')">
                            <td class="font-mono text-xs font-semibold">{{ $t['number'] }}</td>
                            <td>{{ $warehouses[$t['from_warehouse_id']] ?? '' }}</td>
                            <td>{{ $warehouses[$t['to_warehouse_id']] ?? '' }}</td>
                            <td class="num">{{ count($t['lines'] ?? []) }}</td>
                            <td>{{ fmt()->dateTime($t['created_at']) }}</td>
                            <td><x-ui.badge :status="$t['status']"/></td>
                        </tr>
                    @empty
                        <x-ui.empty colspan="6" icon="truck" title="No transfers"/>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-ui.pager :paginator="$meta"/>
    </div>

    <x-ui.modal wire:model="form" title="New transfer" size="lg">
        @if ($form)
            <form wire:submit="save" id="transfer-form" class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.field label="From" for="form.from_warehouse_id">
                        <select id="form.from_warehouse_id" wire:model="form.from_warehouse_id" class="form-select"><option value="">Choose…</option>@foreach ($warehouses as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
                    </x-ui.field>
                    <x-ui.field label="To" for="form.to_warehouse_id">
                        <select id="form.to_warehouse_id" wire:model="form.to_warehouse_id" class="form-select"><option value="">Choose…</option>@foreach ($warehouses as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
                    </x-ui.field>
                    <x-ui.field label="Notes" for="form.notes" class="sm:col-span-2"><input id="form.notes" wire:model="form.notes" class="form-input"></x-ui.field>
                </div>
                @error('form.lines') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
                @foreach ($form['lines'] as $i => $l)
                    <div class="grid items-start gap-2 sm:grid-cols-[1fr_9rem_auto]" wire:key="tl-{{ $i }}">
                        <x-ui.field :for="'form.lines.'.$i.'.product_id'">@include('livewire.partials.product-picker', ['target' => 'line.'.$i, 'selected' => $l['name']])</x-ui.field>
                        <x-ui.field :for="'form.lines.'.$i.'.quantity'"><input wire:model="form.lines.{{ $i }}.quantity" class="form-input py-2" placeholder="Base units" inputmode="decimal"></x-ui.field>
                        <button type="button" class="p-2 text-slate-400 hover:text-rose-600" wire:click="removeLine({{ $i }})" aria-label="Remove"><x-heroicon-o-trash class="size-4"/></button>
                    </div>
                @endforeach
                <button type="button" class="link text-sm" wire:click="addLine">+ Add line</button>
                <p class="text-xs text-slate-500">Batches are chosen first-expiry-first-out when the transfer is dispatched.</p>
            </form>
        @endif
        <x-slot:footer>
            <button type="button" class="btn-light" @click="open = null">Cancel</button>
            <button type="submit" form="transfer-form" class="btn-primary" wire:loading.attr="disabled">Create draft</button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal wire:model="openId" :title="'Transfer '.($detail['number'] ?? '')" size="lg">
        @if ($detail)
            <div class="mb-4 flex flex-wrap items-center gap-2 text-sm">
                <span class="font-medium">{{ $warehouses[$detail['from_warehouse_id']] ?? '' }}</span><x-heroicon-m-arrow-long-right class="size-5 text-slate-400"/><span class="font-medium">{{ $warehouses[$detail['to_warehouse_id']] ?? '' }}</span>
                <x-ui.badge :status="$detail['status']" class="ml-2"/>
            </div>
            @error('receive.lines') <p class="mb-2 text-xs text-rose-600">{{ $message }}</p> @enderror
            <div class="table-wrap rounded-lg border border-slate-200">
                <table class="data-table">
                    <thead><tr><th>Product</th><th>Batch</th><th class="num">Quantity</th><th class="num">Received</th>@if (in_array($detail['status'], ['dispatched', 'partially_received'], true))<th class="num">Receive now</th>@endif</tr></thead>
                    <tbody>
                        @foreach ($detail['lines'] as $l)
                            <tr wire:key="tdl-{{ $l['id'] }}">
                                <td class="font-medium text-navy-900">{{ $names[$l['product_id']] ?? '' }}</td>
                                <td class="font-mono text-xs">{{ $l['batch_id'] ? ($batches[$l['batch_id']] ?? '') : 'chosen at dispatch' }}</td>
                                <td class="num">{{ fmt()->number($l['quantity'], 2) }}</td>
                                <td class="num">{{ fmt()->number($l['received_quantity'], 2) }}</td>
                                @if (in_array($detail['status'], ['dispatched', 'partially_received'], true))
                                    <td class="num"><input wire:model="receiving.{{ $l['id'] }}" inputmode="decimal" class="form-input w-28 py-1.5 text-right" aria-label="Receive now"></td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($detail['notes'])<p class="mt-3 text-sm text-slate-600">{{ $detail['notes'] }}</p>@endif
        @endif
        <x-slot:footer>
            @if ($detail && $canTransfer)
                @if ($detail['status'] === 'draft')
                    <button type="button" class="btn-danger" wire:click="act('cancel')" wire:confirm="Cancel this transfer?">Cancel transfer</button>
                    <button type="button" class="btn-primary" wire:click="act('dispatch')">Dispatch</button>
                @elseif (in_array($detail['status'], ['dispatched', 'partially_received'], true))
                    <button type="button" class="btn-primary" wire:click="receive">Receive</button>
                @endif
            @endif
        </x-slot:footer>
    </x-ui.modal>
</div>

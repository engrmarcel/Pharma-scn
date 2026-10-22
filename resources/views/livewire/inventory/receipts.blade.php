<div class="space-y-6">
    @if ($done)
        <div class="card flex flex-wrap items-center justify-between gap-3 border-emerald-200 bg-emerald-50/60 p-4 text-sm" role="status">
            <p class="text-emerald-800"><strong>{{ $done['lines'] }} {{ \Illuminate\Support\Str::plural('line', $done['lines']) }}</strong> received into {{ $done['warehouse'] }}@if ($done['reference']) · ref {{ $done['reference'] }}@endif.</p>
            <a href="{{ route('inventory.movements') }}" class="link">See the movements</a>
        </div>
    @endif

    <form wire:submit="receive" class="space-y-6">
        <x-ui.card title="Receipt" icon="inbox-arrow-down">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <x-ui.field label="Warehouse" for="receipt.warehouse_id">
                    <select id="receipt.warehouse_id" wire:model.live="form.warehouse_id" class="form-select"><option value="">Choose…</option>@foreach ($warehouses as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
                </x-ui.field>
                <x-ui.field label="Reason" for="receipt.reason_code">
                    <select id="receipt.reason_code" wire:model="form.reason_code" class="form-select">
                        @foreach (['opening_balance' => 'Opening balance', 'supplier_delivery' => 'Supplier delivery (no PO)', 'customer_return' => 'Customer return', 'receipt' => 'Receipt', 'other' => 'Other'] as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
                    </select>
                </x-ui.field>
                <x-ui.field label="Supplier (optional)" for="receipt.supplier_id">
                    <select id="receipt.supplier_id" wire:model="form.supplier_id" class="form-select"><option value="">None</option>@foreach ($suppliers as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
                </x-ui.field>
                <x-ui.field label="Reference" for="receipt.reference"><input id="receipt.reference" wire:model="form.reference" class="form-input" placeholder="Delivery note no."></x-ui.field>
            </div>
            <p class="mt-3 text-xs text-slate-500">Goods ordered on a purchase order are received under Procurement → Goods receipts, so they match the order and invoice.</p>
        </x-ui.card>

        <x-ui.card title="Lines" icon="list-bullet">
            @error('receipt.lines') <p class="mb-2 text-xs text-rose-600">{{ $message }}</p> @enderror
            <div class="space-y-3">
                @foreach ($lines as $i => $l)
                    <div class="grid gap-2 rounded-xl border border-slate-200 p-3 md:grid-cols-6 xl:grid-cols-12" wire:key="rl-{{ $i }}">
                        <x-ui.field label="Product" :for="'receipt.lines.'.$i.'.product_id'" class="md:col-span-3 xl:col-span-3">@include('livewire.partials.product-picker', ['target' => 'line.'.$i, 'selected' => $l['name']])</x-ui.field>
                        <x-ui.field label="Batch" :for="'receipt.lines.'.$i.'.batch_number'" class="xl:col-span-2"><input wire:model="lines.{{ $i }}.batch_number" class="form-input py-2 font-mono uppercase"></x-ui.field>
                        <x-ui.field label="Expiry" :for="'receipt.lines.'.$i.'.expiry_date'" class="xl:col-span-2"><input type="date" wire:model="lines.{{ $i }}.expiry_date" class="form-input py-2"></x-ui.field>
                        <x-ui.field label="Quantity" :for="'receipt.lines.'.$i.'.quantity'"><input wire:model="lines.{{ $i }}.quantity" inputmode="decimal" class="form-input py-2"></x-ui.field>
                        <x-ui.field label="Pack" :for="'receipt.lines.'.$i.'.package_level_code'" class="xl:col-span-2">
                            <select wire:model="lines.{{ $i }}.package_level_code" class="form-select py-2">@forelse ($l['levels'] as $code => $name)<option value="{{ $code }}">{{ $name }}</option>@empty<option value="">Base unit</option>@endforelse</select>
                        </x-ui.field>
                        <x-ui.field label="Unit cost" :for="'receipt.lines.'.$i.'.unit_cost'"><input wire:model="lines.{{ $i }}.unit_cost" inputmode="decimal" class="form-input py-2"></x-ui.field>
                        <x-ui.field label="Manufactured" :for="'receipt.lines.'.$i.'.manufacturing_date'" class="xl:col-span-2"><input type="date" wire:model="lines.{{ $i }}.manufacturing_date" class="form-input py-2"></x-ui.field>
                        <x-ui.field label="Quality" :for="'receipt.lines.'.$i.'.quality_status'" class="xl:col-span-2">
                            <select wire:model="lines.{{ $i }}.quality_status" class="form-select py-2"><option value="released">Released</option><option value="quarantined">Quarantine first</option></select>
                        </x-ui.field>
                        <x-ui.field label="Bin" :for="'receipt.lines.'.$i.'.bin_location_id'" class="xl:col-span-2">
                            <select wire:model="lines.{{ $i }}.bin_location_id" class="form-select py-2"><option value="">—</option>@foreach ($bins as $id => $code)<option value="{{ $id }}">{{ $code }}</option>@endforeach</select>
                        </x-ui.field>
                        <div class="flex items-end justify-end xl:col-span-6">
                            @if (count($lines) > 1)<button type="button" class="link text-xs text-rose-700" wire:click="removeLine({{ $i }})">Remove line</button>@endif
                        </div>
                    </div>
                @endforeach
            </div>
            <div class="mt-4 flex items-center justify-between">
                <button type="button" class="link text-sm" wire:click="addLine">+ Add line</button>
                <button class="btn-primary" wire:loading.attr="disabled"><x-heroicon-o-check class="size-4"/>Receive stock</button>
            </div>
        </x-ui.card>
    </form>
</div>

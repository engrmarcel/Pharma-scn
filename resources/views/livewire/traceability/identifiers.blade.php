<div>
    @if ($notice)
        <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900" role="status">{{ $notice }}</div>
    @endif

    <x-ui.toolbar>
        <x-ui.field label="Kind" for="kind">
            <select id="kind" wire:model.live="kind" class="form-select w-40"><option value="">All</option>@foreach (['batch', 'product', 'serial', 'sscc'] as $k)<option value="{{ $k }}">{{ strtoupper($k) === 'SSCC' ? 'SSCC' : ucfirst($k) }}</option>@endforeach</select>
        </x-ui.field>
        <x-ui.field label="Batch" for="batchNumber"><input id="batchNumber" wire:model.live.debounce.500ms="batchNumber" class="form-input w-44 font-mono uppercase"></x-ui.field>
        <x-slot:actions>
            @if ($canManage)
                <button type="button" class="btn-light" wire:click="create('serials')"><x-heroicon-o-hashtag class="size-4"/>Commission serials</button>
                <button type="button" class="btn-primary" wire:click="create('issue')"><x-heroicon-o-plus class="size-4"/>Issue identifier</button>
            @endif
        </x-slot:actions>
    </x-ui.toolbar>

    @if ($canManage && $units->isNotEmpty() && collect($selected)->filter()->isNotEmpty())
        <div class="mb-4 flex flex-wrap items-end gap-2 rounded-lg border border-slate-200 bg-white p-3">
            <x-ui.field label="Pack the ticked identifiers into" for="aggregate.child_ids">
                <select wire:model="parentId" class="form-select w-64"><option value="">Choose a logistic unit…</option>@foreach ($units as $id => $sscc)<option value="{{ $id }}">SSCC {{ $sscc }}</option>@endforeach</select>
            </x-ui.field>
            <button type="button" class="btn-primary" wire:click="aggregate">Aggregate</button>
        </div>
    @endif

    <div class="card overflow-hidden">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr>@if ($canManage)<th class="w-8"></th>@endif<th>Kind</th><th>Product</th><th>Batch</th><th>Serial</th><th>Expiry</th><th>GS1</th><th>Status</th></tr></thead>
                <tbody>
                    @forelse ($rows as $i)
                        <tr wire:key="id-{{ $i['id'] }}">
                            @if ($canManage)<td><input type="checkbox" wire:model.live="selected.{{ $i['id'] }}" class="form-check" aria-label="Select"></td>@endif
                            <td class="cursor-pointer" wire:click="$set('openId', '{{ $i['id'] }}')"><x-ui.badge tone="blue">{{ $i['kind'] }}</x-ui.badge></td>
                            <td class="cursor-pointer font-medium text-navy-900" wire:click="$set('openId', '{{ $i['id'] }}')">{{ $names[$i['product_id']] ?? '—' }}</td>
                            <td class="font-mono text-xs">{{ $i['batch_number'] ?? '—' }}</td>
                            <td class="font-mono text-xs">{{ $i['serial'] ?? '—' }}</td>
                            <td>{{ $i['expiry_date'] ? fmt()->date($i['expiry_date']) : '—' }}</td>
                            <td class="font-mono text-xs">{{ $i['gs1'] ?? '—' }}</td>
                            <td><x-ui.badge :status="$i['status']"/></td>
                        </tr>
                    @empty
                        <x-ui.empty colspan="8" icon="tag" title="No identifiers yet"/>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-ui.pager :paginator="$meta"/>
    </div>

    <x-ui.modal wire:model="openId" title="Identifier" size="md">
        @if ($detail)
            <div class="flex flex-col items-center gap-3 sm:flex-row sm:items-start">
                @if ($qr)<img src="{{ $qr }}" alt="QR code" class="size-40 rounded-lg border border-slate-200 bg-white p-2">@endif
                <x-ui.dl :cols="1" :items="['Kind' => $detail['kind'], 'Product' => $names[$detail['product_id']] ?? null, 'Batch' => $detail['batch_number'], 'Serial' => $detail['serial'],
                    'Status' => $detail['status'].($detail['status_reason'] ? ' · '.$detail['status_reason'] : ''), 'Link' => $detail['url']]"/>
            </div>
            @if ($canManage && $detail['status'] === 'active')
                <form class="mt-4 flex flex-wrap items-end gap-2" wire:submit="changeStatus('revoke')">
                    <x-ui.field label="Reason" for="status.reason" class="flex-1"><input id="status.reason" wire:model="reason" class="form-input py-2"></x-ui.field>
                    <button type="button" class="btn-light" wire:click="changeStatus('decommission')">Decommission</button>
                    <button class="btn-danger">Revoke</button>
                </form>
            @endif
        @endif
    </x-ui.modal>

    <x-ui.modal wire:model="form" :title="($form['mode'] ?? '') === 'serials' ? 'Commission serial numbers' : 'Issue an identifier'" size="md">
        @if ($form)
            <form wire:submit="save" id="ident-form" class="space-y-4">
                @if ($form['mode'] === 'issue')
                    <x-ui.field label="Kind" for="form.kind">
                        <select id="form.kind" wire:model.live="form.kind" class="form-select"><option value="batch">Batch QR</option><option value="product">Product QR</option><option value="sscc">Logistic unit (SSCC)</option></select>
                    </x-ui.field>
                @endif
                @if (($form['kind'] ?? '') !== 'sscc' || $form['mode'] === 'serials')
                    <x-ui.field label="Product" for="form.product_id">@include('livewire.partials.product-picker', ['target' => 'ident', 'selected' => $form['product_name']])</x-ui.field>
                @endif
                @if ($form['mode'] === 'serials' || $form['kind'] === 'batch')
                    <x-ui.field label="Batch number" for="form.batch_number"><input id="form.batch_number" wire:model="form.batch_number" class="form-input font-mono uppercase"></x-ui.field>
                @endif
                @if ($form['mode'] === 'issue' && $form['kind'] === 'sscc')
                    <x-ui.field label="Serial reference" for="form.serial" hint="Up to 18 digits; the check digit is added."><input id="form.serial" wire:model="form.serial" class="form-input font-mono"></x-ui.field>
                @endif
                @if ($form['mode'] === 'serials')
                    <x-ui.field label="Serial numbers" for="form.serials" hint="One per line, or separated by commas."><textarea id="form.serials" wire:model="form.serials" rows="5" class="form-input font-mono"></textarea></x-ui.field>
                @endif
            </form>
        @endif
        <x-slot:footer>
            <button type="button" class="btn-light" @click="open = null">Cancel</button>
            <button type="submit" form="ident-form" class="btn-primary" wire:loading.attr="disabled">{{ ($form['mode'] ?? '') === 'serials' ? 'Commission' : 'Issue' }}</button>
        </x-slot:footer>
    </x-ui.modal>
</div>

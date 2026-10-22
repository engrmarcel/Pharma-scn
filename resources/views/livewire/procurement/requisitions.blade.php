<div>
    <x-ui.toolbar>
        <x-ui.field label="Status" for="status">
            <select id="status" wire:model.live="status" class="form-select w-48">
                <option value="">All</option>@foreach (['draft', 'submitted', 'approved', 'rejected', 'converted'] as $s)<option value="{{ $s }}">{{ ucfirst(str_replace('_', ' ', $s)) }}</option>@endforeach
            </select>
        </x-ui.field>
        <x-slot:actions>
            <a href="{{ route('procurement.suggestions') }}" class="btn-light"><x-heroicon-o-light-bulb class="size-4"/>Reorder suggestions</a>
            @if ($can['raise'])<button type="button" class="btn-primary" wire:click="create"><x-heroicon-o-plus class="size-4"/>New requisition</button>@endif
        </x-slot:actions>
    </x-ui.toolbar>

    <div class="card overflow-hidden">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Number</th><th>Warehouse</th><th>Needed by</th><th class="num">Lines</th><th>Raised</th><th>Status</th></tr></thead>
                <tbody>
                    @forelse ($rows as $r)
                        <tr wire:key="r-{{ $r['id'] }}" class="cursor-pointer" wire:click="open('{{ $r['id'] }}')">
                            <td class="font-mono text-xs font-semibold">{{ $r['number'] }}</td>
                            <td>{{ $warehouses[$r['warehouse_id']] ?? '' }}</td>
                            <td>{{ $r['needed_by'] ? fmt()->date($r['needed_by']) : '—' }}</td>
                            <td class="num">{{ count($r['lines'] ?? []) }}</td>
                            <td>{{ fmt()->dateTime($r['created_at']) }}</td>
                            <td><x-ui.badge :status="$r['status']"/></td>
                        </tr>
                    @empty
                        <x-ui.empty colspan="6" icon="clipboard-document-list" title="No requisitions"/>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-ui.pager :paginator="$meta"/>
    </div>

    <x-ui.modal wire:model="doc" title="New purchase requisition" size="xl">
        @if ($doc)
            <form wire:submit="save" id="req-form" class="space-y-5">
                <div class="grid gap-4 sm:grid-cols-3">
                    <x-ui.field label="Deliver to" for="doc.warehouse_id">
                        <select id="doc.warehouse_id" wire:model="doc.warehouse_id" class="form-select"><option value="">Choose…</option>@foreach ($warehouses as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
                    </x-ui.field>
                    <x-ui.field label="Needed by" for="doc.needed_by"><input id="doc.needed_by" type="date" wire:model="doc.needed_by" class="form-input"></x-ui.field>
                    <x-ui.field label="Notes" for="doc.notes"><input id="doc.notes" wire:model="doc.notes" class="form-input"></x-ui.field>
                </div>
                @include('livewire.partials.document-lines', ['form' => 'doc', 'showPrice' => false, 'showTax' => false])
            </form>
        @endif
        <x-slot:footer>
            <button type="button" class="btn-light" @click="open = null">Cancel</button>
            <button type="submit" form="req-form" class="btn-primary" wire:loading.attr="disabled">Save draft</button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal wire:model="openId" :title="'Requisition '.($detail['number'] ?? '')" size="lg">
        @if ($detail)
            <div class="mb-4 flex flex-wrap items-center gap-2"><x-ui.badge :status="$detail['status']"/><span class="text-sm text-slate-500">{{ $warehouses[$detail['warehouse_id']] ?? '' }} · needed {{ $detail['needed_by'] ? fmt()->date($detail['needed_by']) : 'any time' }}</span></div>
            <ul class="divide-y divide-slate-100 rounded-lg border border-slate-200 text-sm">
                @foreach ($detail['lines'] as $l)
                    <li class="flex justify-between gap-3 px-3 py-2"><span>{{ $names[$l['product_id']] ?? '' }} <span class="text-slate-500">· {{ $l['package_level_code'] }}</span></span><span class="tabular-nums">{{ fmt()->number($l['quantity'], 2) }}</span></li>
                @endforeach
            </ul>
            @if ($detail['notes'])<p class="mt-3 text-sm text-slate-600">{{ $detail['notes'] }}</p>@endif
            @if ($detail['decision_notes'] ?? null)<p class="mt-2 text-sm text-slate-600"><strong>Decision:</strong> {{ $detail['decision_notes'] }}</p>@endif

            @if ($detail['status'] === 'submitted' && $can['approve'])
                <x-ui.field label="Decision notes" for="decision.notes" class="mt-4"><textarea id="decision.notes" wire:model="decisionNotes" rows="2" class="form-input"></textarea></x-ui.field>
            @endif
            @if ($detail['status'] === 'approved' && $can['order'])
                <x-ui.field label="Supplier for the order" for="convert.supplier_id" hint="Leave empty to use each line's suggested supplier." class="mt-4">
                    <select id="convert.supplier_id" wire:model="convertSupplier" class="form-select"><option value="">Suggested supplier</option>@foreach ($suppliers as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
                </x-ui.field>
            @endif
        @endif
        <x-slot:footer>
            @if ($detail)
                @if ($detail['status'] === 'draft' && $can['raise'])<button type="button" class="btn-primary" wire:click="act('submit')">Submit for approval</button>@endif
                @if ($detail['status'] === 'submitted' && $can['approve'])
                    <button type="button" class="btn-danger" wire:click="act('reject')">Reject</button>
                    <button type="button" class="btn-primary" wire:click="act('approve')">Approve</button>
                @endif
                @if ($detail['status'] === 'approved' && $can['order'])<button type="button" class="btn-primary" wire:click="act('convert')">Create purchase order</button>@endif
            @endif
        </x-slot:footer>
    </x-ui.modal>
</div>

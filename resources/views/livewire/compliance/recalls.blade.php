<div>
    <x-ui.toolbar>
        <x-ui.field label="Status" for="status">
            <select id="status" wire:model.live="status" class="form-select w-40"><option value="">All</option>@foreach (['draft', 'active', 'closed', 'cancelled'] as $s)<option value="{{ $s }}">{{ ucfirst($s) }}</option>@endforeach</select>
        </x-ui.field>
        <x-slot:actions>@if ($canInitiate)<button type="button" class="btn-primary" wire:click="create"><x-heroicon-o-exclamation-triangle class="size-4"/>New recall</button>@endif</x-slot:actions>
    </x-ui.toolbar>

    <div class="card overflow-hidden">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Number</th><th>Title</th><th>Product</th><th>Batches</th><th>Class</th><th>Depth</th><th>Activated</th><th>Status</th></tr></thead>
                <tbody>
                    @forelse ($rows as $r)
                        <tr wire:key="rc-{{ $r['id'] }}" class="cursor-pointer" wire:click="$set('openId', '{{ $r['id'] }}')">
                            <td class="font-mono text-xs font-semibold">{{ $r['number'] }}</td>
                            <td class="font-medium text-navy-900">{{ $r['title'] }}</td>
                            <td>{{ $names[$r['product_id']] ?? '' }}</td>
                            <td class="font-mono text-xs">{{ implode(', ', $r['batch_numbers']) }}</td>
                            <td><x-ui.badge :tone="$r['classification'] === 'class_i' ? 'red' : 'amber'">{{ strtoupper(str_replace('class_', 'class ', $r['classification'])) }}</x-ui.badge></td>
                            <td>{{ $r['depth'] }}</td>
                            <td>{{ $r['activated_at'] ? fmt()->dateTime($r['activated_at']) : '—' }}</td>
                            <td><x-ui.badge :status="$r['status'] === 'active' ? 'recalled' : $r['status']">{{ $r['status'] }}</x-ui.badge></td>
                        </tr>
                    @empty
                        <x-ui.empty colspan="8" icon="exclamation-triangle" title="No recalls"/>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <x-ui.modal wire:model="openId" :title="($detail['number'] ?? '').' · '.($detail['title'] ?? '')" size="xl">
        @if ($detail)
            <x-ui.dl :cols="3" :items="['Product' => $names[$detail['product_id']] ?? null, 'Batches' => implode(', ', $detail['batch_numbers']), 'Status' => $detail['status'],
                'Classification' => str_replace('_', ' ', $detail['classification']), 'Depth' => $detail['depth'], 'Authority reference' => trim(($detail['authority'] ?? '').' '.($detail['regulatory_reference'] ?? ''))]"/>
            <div class="mt-4 grid gap-4 sm:grid-cols-2 text-sm">
                <div><p class="text-xs font-semibold uppercase text-slate-400">Reason</p><p class="mt-1 whitespace-pre-line text-slate-700">{{ $detail['reason'] }}</p></div>
                <div><p class="text-xs font-semibold uppercase text-slate-400">Instructions to holders</p><p class="mt-1 whitespace-pre-line text-slate-700">{{ $detail['quarantine_instructions'] }}</p></div>
            </div>
            @foreach (['closure_notes' => 'Closure notes', 'closure_override_reason' => 'Closed despite open notices because', 'cancellation_reason' => 'Cancelled because'] as $k => $label)
                @if ($detail[$k])<p class="mt-3 text-sm"><strong>{{ $label }}:</strong> {{ $detail[$k] }}</p>@endif
            @endforeach

            @if ($exposure)
                <h3 class="mb-2 mt-6 text-sm font-semibold text-navy-950">Exposure</h3>
                <div class="grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
                    <x-ui.stat label="Notified" :value="$exposure['totals']['organizations_notified']"/>
                    <x-ui.stat label="Acknowledged" :value="$exposure['totals']['acknowledged']" tone="green"/>
                    <x-ui.stat label="Held at notice" :value="fmt()->number($exposure['totals']['held_at_notice'], 2)"/>
                    <x-ui.stat label="Still held" :value="fmt()->number($exposure['totals']['still_held'], 2)" tone="amber"/>
                    <x-ui.stat label="Dispensed before" :value="fmt()->number($exposure['totals']['dispensed_before_recall'], 2)" tone="gold"/>
                    <x-ui.stat label="Unaccounted" :value="fmt()->number($exposure['totals']['unaccounted'], 2)" tone="red"/>
                </div>
                <div class="table-wrap mt-3 rounded-lg border border-slate-200">
                    <table class="data-table">
                        <thead><tr><th>Organization</th><th>Notice</th><th class="num">Received</th><th class="num">Held</th><th class="num">Still held</th><th class="num">Shipped on</th><th class="num">Shipped off platform</th><th class="num">Unaccounted</th></tr></thead>
                        <tbody>
                            @foreach ($exposure['organizations'] as $o)
                                <tr><td class="font-medium">{{ $o['organization'] }}</td><td><x-ui.badge :status="$o['notice_status']"/></td>
                                    <td class="num">{{ fmt()->number($o['received_before_recall'], 2) }}</td><td class="num">{{ fmt()->number($o['held_at_notice'], 2) }}</td><td class="num">{{ fmt()->number($o['still_held'], 2) }}</td>
                                    <td class="num">{{ fmt()->number($o['shipped_to_platform_organizations'], 2) }}</td><td class="num">{{ fmt()->number($o['shipped_outside_platform'], 2) }}</td>
                                    <td class="num font-semibold {{ (float) $o['unaccounted'] > 0 ? 'text-rose-700' : '' }}">{{ fmt()->number($o['unaccounted'], 2) }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="mt-2 text-xs text-slate-500">{{ $exposure['coverage']['statement'] }}</p>
            @endif

            @if ($closing)
                <form wire:submit="finish" class="mt-5 space-y-3 rounded-lg border border-slate-200 bg-slate-50 p-4">
                    @if ($closing['action'] === 'close')
                        <x-ui.field label="Closure notes" for="closing.notes"><textarea id="closing.notes" wire:model="closing.notes" rows="2" class="form-input"></textarea></x-ui.field>
                        <x-ui.field label="Override reason (only if notices are still open)" for="closing.override_reason"><input id="closing.override_reason" wire:model="closing.override_reason" class="form-input"></x-ui.field>
                    @else
                        <x-ui.field label="Why cancel" for="closing.reason"><textarea id="closing.reason" wire:model="closing.reason" rows="2" class="form-input"></textarea></x-ui.field>
                    @endif
                    <div class="flex justify-end gap-2"><button type="button" class="btn-light btn-sm" wire:click="$set('closing', null)">Back</button><button class="btn-primary btn-sm">Confirm</button></div>
                </form>
            @endif
        @endif
        <x-slot:footer>
            @if ($detail && $canInitiate && ! $closing)
                @if ($detail['status'] === 'draft')
                    <button type="button" class="btn-danger" wire:click="startClose('cancel')">Cancel recall</button>
                    <button type="button" class="btn-primary" wire:click="activate" wire:confirm="Activate this recall? Every organization holding the batches is notified and their stock is quarantined.">Activate and notify</button>
                @elseif ($detail['status'] === 'active')
                    <button type="button" class="btn-primary" wire:click="startClose('close')">Close recall</button>
                @endif
            @endif
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal wire:model="form" title="New recall" size="lg">
        @if ($form)
            <form wire:submit="save" id="recall-form" class="grid gap-4 sm:grid-cols-2">
                <x-ui.field label="Product" for="form.product_id" class="sm:col-span-2">@include('livewire.partials.product-picker', ['target' => 'recall', 'selected' => $form['product_name']])</x-ui.field>
                <x-ui.field label="Batch numbers" for="form.batch_numbers" hint="Separate with commas or spaces." class="sm:col-span-2"><input id="form.batch_numbers" wire:model="form.batch_numbers" class="form-input font-mono uppercase"></x-ui.field>
                <x-ui.field label="Title" for="form.title" class="sm:col-span-2"><input id="form.title" wire:model="form.title" class="form-input"></x-ui.field>
                <x-ui.field label="Classification" for="form.classification"><select id="form.classification" wire:model="form.classification" class="form-select">@foreach ($classifications as $c)<option value="{{ $c }}">{{ ucfirst(str_replace('_', ' ', $c)) }}</option>@endforeach</select></x-ui.field>
                <x-ui.field label="Depth" for="form.depth"><select id="form.depth" wire:model="form.depth" class="form-select">@foreach ($depths as $d)<option value="{{ $d }}">{{ ucfirst($d) }} level</option>@endforeach</select></x-ui.field>
                <x-ui.field label="Authority" for="form.authority"><input id="form.authority" wire:model="form.authority" class="form-input" placeholder="NAFDAC"></x-ui.field>
                <x-ui.field label="Regulatory reference" for="form.regulatory_reference"><input id="form.regulatory_reference" wire:model="form.regulatory_reference" class="form-input"></x-ui.field>
                <x-ui.field label="Reason" for="form.reason" class="sm:col-span-2"><textarea id="form.reason" wire:model="form.reason" rows="3" class="form-input"></textarea></x-ui.field>
                <x-ui.field label="Instructions to holders" for="form.quarantine_instructions" class="sm:col-span-2"><textarea id="form.quarantine_instructions" wire:model="form.quarantine_instructions" rows="3" class="form-input"></textarea></x-ui.field>
            </form>
        @endif
        <x-slot:footer>
            <button type="button" class="btn-light" @click="open = null">Cancel</button>
            <button type="submit" form="recall-form" class="btn-primary" wire:loading.attr="disabled">Save draft</button>
        </x-slot:footer>
    </x-ui.modal>
</div>

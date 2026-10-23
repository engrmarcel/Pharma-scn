<div>
    <x-ui.tabs :current="$tab" :tabs="array_filter(['products' => 'Products', 'requests' => $can['review'] ? 'Proposals from organizations' : null])"/>

    @if ($tab === 'products')
        <x-ui.toolbar>
            <x-ui.search placeholder="Name, SKU, GTIN or registration number…"/>
            <x-slot:actions>@if ($can['manage'])<button type="button" class="btn-primary" wire:click="create"><x-heroicon-o-plus class="size-4"/>New product</button>@endif</x-slot:actions>
        </x-ui.toolbar>
        <div class="card overflow-hidden">
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Product</th><th>SKU</th><th>Form</th><th>Manufacturer</th><th>Version</th><th>Status</th></tr></thead>
                    <tbody>
                        @forelse ($products as $p)
                            <tr wire:key="p-{{ $p['id'] }}" class="cursor-pointer" wire:click="$set('openId', '{{ $p['id'] }}')">
                                <td class="font-medium text-navy-900">{{ $p['display_name'] }}</td><td class="font-mono text-xs">{{ $p['platform_sku'] }}</td><td>{{ $p['dosage_form_code'] }}</td>
                                <td>{{ $p['manufacturer']['name'] ?? '—' }}</td><td>{{ $p['version'] }}</td><td><x-ui.badge :status="$p['status']"/></td>
                            </tr>
                        @empty
                            <x-ui.empty colspan="6" icon="beaker" title="No products match"/>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <x-ui.pager :paginator="$meta"/>
        </div>
    @else
        <x-ui.toolbar>
            <x-ui.field label="Status" for="requestStatus"><select id="requestStatus" wire:model.live="requestStatus" class="form-select w-40"><option value="pending">Pending</option><option value="approved">Approved</option><option value="rejected">Rejected</option></select></x-ui.field>
            <x-ui.field label="Review notes" for="decision.notes" class="flex-1"><input id="decision.notes" wire:model="notes" class="form-input" placeholder="Required to reject; shown to the organization"></x-ui.field>
        </x-ui.toolbar>
        <div class="space-y-3">
            @forelse ($requests as $r)
                <div class="card p-4" wire:key="cr-{{ $r['id'] }}">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div><p class="font-semibold text-navy-950">{{ $r['type'] === 'create' ? 'New product' : 'Correction' }}: {{ trim(($r['payload']['generic_name'] ?? '').' '.($r['payload']['strength_text'] ?? '')) ?: ($r['payload']['brand_name'] ?? '') }}</p>
                            <p class="text-xs text-slate-500">From {{ $orgs[$r['organization_id']] ?? '' }} · {{ fmt()->dateTime($r['created_at']) }}{{ $r['base_version'] ? ' · based on version '.$r['base_version'] : '' }}</p></div>
                        <x-ui.badge :status="$r['status']"/>
                    </div>
                    <pre class="mt-3 max-h-48 overflow-auto rounded-lg bg-slate-50 p-3 text-xs">{{ json_encode($r['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                    @if ($r['review_notes'])<p class="mt-2 text-sm text-slate-600">{{ $r['review_notes'] }}</p>@endif
                    @if ($r['status'] === 'pending')
                        <div class="mt-3 flex justify-end gap-2"><button type="button" class="btn-danger btn-sm" wire:click="decide('{{ $r['id'] }}', 'reject')">Reject</button><button type="button" class="btn-primary btn-sm" wire:click="decide('{{ $r['id'] }}', 'approve')">Approve and publish</button></div>
                    @endif
                </div>
            @empty
                <x-ui.card><x-ui.empty icon="pencil-square" title="No proposals"/></x-ui.card>
            @endforelse
            <x-ui.pager :paginator="$meta"/>
        </div>
    @endif

    <x-ui.modal wire:model="openId" :title="$detail['display_name'] ?? 'Product'" size="xl">
        @if ($detail)
            <x-ui.dl :cols="3" :items="['Generic name' => $detail['generic_name'], 'Brand' => $detail['brand_name'], 'Strength' => $detail['strength_text'], 'Form' => $detail['dosage_form_code'],
                'Route' => $detail['route_code'], 'Manufacturer' => $detail['manufacturer']['name'] ?? null, 'Storage' => $detail['storage']['conditions'], 'Cold chain' => $detail['storage']['requires_cold_chain'] ? 'Yes' : 'No',
                'Status' => $detail['status'], 'Version' => $detail['version'], 'Ingredients' => collect($detail['ingredients'])->map(fn ($i) => $i['name'].' '.(float) $i['strength_value'].' '.$i['strength_unit'])->implode(', '),
                'Packs' => collect($detail['package_levels'])->pluck('name')->implode(' › '), 'Identifiers' => collect($detail['identifiers'])->map(fn ($i) => $i['type'].' '.$i['value'])->implode(', ')]"/>
            <h3 class="mb-2 mt-5 text-sm font-semibold text-navy-950">Regulatory identifiers</h3>
            <ul class="divide-y divide-slate-100 rounded-lg border border-slate-200 text-sm">
                @forelse ($detail['regulatory_identifiers'] as $ri)
                    <li class="flex flex-wrap items-center justify-between gap-2 px-3 py-2">
                        <span>{{ $ri['authority'] }} {{ str_replace('_', ' ', $ri['identifier_type']) }} <span class="font-mono">{{ $ri['value'] }}</span> · declared {{ $ri['declared']['status'] }}</span>
                        <span class="flex items-center gap-2"><x-ui.badge :status="$ri['platform_verification']['status']"/>@if ($can['verify'])<button type="button" class="link text-xs" wire:click="startVerify('{{ $ri['id'] }}')">Record a check</button>@endif</span>
                    </li>
                @empty
                    <li class="px-3 py-2 text-slate-500">None recorded.</li>
                @endforelse
            </ul>
            @if ($identifier)
                <form wire:submit="saveIdentifier" class="mt-3 grid gap-2 rounded-lg border border-slate-200 bg-slate-50 p-3 sm:grid-cols-3">
                    <x-ui.field label="Authority" for="identifier.authority_code"><select wire:model="identifier.authority_code" class="form-select py-2">@foreach ($authorities as $c => $n)<option value="{{ $c }}">{{ $c }}</option>@endforeach</select></x-ui.field>
                    <x-ui.field label="Type" for="identifier.identifier_type"><input wire:model="identifier.identifier_type" class="form-input py-2"></x-ui.field>
                    <x-ui.field label="Number" for="identifier.value"><input wire:model="identifier.value" class="form-input py-2 font-mono"></x-ui.field>
                    <x-ui.field label="Holder" for="identifier.holder_name"><input wire:model="identifier.holder_name" class="form-input py-2"></x-ui.field>
                    <x-ui.field label="Expires" for="identifier.expiry_date"><input type="date" wire:model="identifier.expiry_date" class="form-input py-2"></x-ui.field>
                    <div class="flex items-end justify-end gap-2"><button type="button" class="btn-light btn-sm" wire:click="$set('identifier', null)">Cancel</button><button class="btn-primary btn-sm">Save</button></div>
                </form>
            @endif
            @if ($verifying)
                <form wire:submit="verify" class="mt-3 grid gap-2 rounded-lg border border-slate-200 bg-slate-50 p-3 sm:grid-cols-2">
                    <x-ui.field label="How it was checked" for="verifying.method"><select wire:model="verifying.method" class="form-select py-2">@foreach (['official_portal_lookup', 'manual_document_review', 'regulator_api', 'other'] as $m)<option value="{{ $m }}">{{ str_replace('_', ' ', $m) }}</option>@endforeach</select></x-ui.field>
                    <x-ui.field label="Result" for="verifying.result"><select wire:model="verifying.result" class="form-select py-2">@foreach (['verified', 'failed', 'inconclusive', 'expired', 'revoked'] as $r)<option value="{{ $r }}">{{ $r }}</option>@endforeach</select></x-ui.field>
                    <x-ui.field label="Source" for="verifying.source"><input wire:model="verifying.source" class="form-input py-2" placeholder="Portal URL or document"></x-ui.field>
                    <x-ui.field label="Notes" for="verifying.notes"><input wire:model="verifying.notes" class="form-input py-2"></x-ui.field>
                    <div class="flex justify-end gap-2 sm:col-span-2"><button type="button" class="btn-light btn-sm" wire:click="$set('verifying', null)">Cancel</button><button class="btn-primary btn-sm">Record</button></div>
                </form>
            @endif
        @endif
        <x-slot:footer>
            @if ($detail && $can['manage'] && ! $identifier && ! $verifying)
                <button type="button" class="btn-light" wire:click="addIdentifier">Add regulatory identifier</button>
                <button type="button" class="btn-primary" wire:click="edit">Edit product</button>
            @endif
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal wire:model="product" :title="($productBase['name'] ?? null) ? 'Edit '.$productBase['name'] : 'New product'" size="xl">
        @if ($product)
            <form wire:submit="save" id="prod-form" class="space-y-4">
                @include('livewire.partials.product-form')
                <x-ui.field label="Status" for="product.status" class="w-48"><select id="product.status" wire:model="product.status" class="form-select">@foreach ($statuses as $s)<option value="{{ $s }}">{{ str_replace('_', ' ', $s) }}</option>@endforeach</select></x-ui.field>
                @error('product.version') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
            </form>
        @endif
        <x-slot:footer><button type="button" class="btn-light" @click="open = null">Cancel</button><button type="submit" form="prod-form" class="btn-primary" wire:loading.attr="disabled">Save</button></x-slot:footer>
    </x-ui.modal>
</div>

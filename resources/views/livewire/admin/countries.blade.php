<div>
    @if (! $detail)
        <div class="card overflow-hidden">
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Country</th><th>Currency</th><th class="num">Organizations</th><th>Readiness</th><th>Status</th></tr></thead>
                    <tbody>
                        @forelse ($countries as $c)
                            <tr wire:key="c-{{ $c['code'] }}" class="cursor-pointer" wire:click="open('{{ $c['code'] }}')">
                                <td><span class="font-mono text-xs text-slate-500">{{ $c['code'] }}</span> <span class="font-semibold">{{ $c['name'] }}</span></td>
                                <td>{{ $c['currency_code'] }}</td><td class="num">{{ number_format($c['organizations']) }}</td>
                                <td><x-ui.badge :status="$c['ready'] ? 'done' : 'pending'">{{ $c['ready'] ? 'Ready' : 'Not ready' }}</x-ui.badge></td>
                                <td><x-ui.badge :status="$c['is_active'] ? 'active' : 'inactive'">{{ $c['is_active'] ? 'Active' : 'Inactive' }}</x-ui.badge></td>
                            </tr>
                        @empty
                            <x-ui.empty colspan="5" icon="globe-europe-africa" title="No countries configured"/>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @else
        <x-ui.toolbar>
            <button type="button" class="link text-sm" wire:click="close">&larr; All countries</button>
            <x-slot:actions>
                @if ($detail['is_active'])
                    <button type="button" class="btn-light btn-sm" wire:click="$set('deactivating', {reason: ''})">Deactivate</button>
                @else
                    <button type="button" class="btn-primary btn-sm" wire:click="activate" wire:confirm="Activate {{ $detail['name'] }}? Organizations will be able to register here.">Activate</button>
                @endif
            </x-slot:actions>
        </x-ui.toolbar>

        <div class="mb-4 flex flex-wrap items-center gap-3">
            <h2 class="text-xl font-semibold">{{ $detail['name'] }} <span class="font-mono text-sm text-slate-500">{{ $detail['code'] }}</span></h2>
            <x-ui.badge :status="$detail['is_active'] ? 'active' : 'inactive'">{{ $detail['is_active'] ? 'Active' : 'Inactive' }}</x-ui.badge>
            <x-ui.badge :status="$detail['readiness']['ready'] ? 'done' : 'pending'">{{ $detail['readiness']['ready'] ? 'Ready to activate' : 'Not ready' }}</x-ui.badge>
        </div>

        <x-ui.tabs :current="$tab" :tabs="['readiness' => 'Readiness', 'basics' => 'Details', 'profile' => 'Profile', 'reviews' => 'Reviews', 'tax' => 'Tax rates', 'regulators' => 'Regulators']"/>

        @if ($tab === 'readiness')
            <x-ui.card title="Readiness checks" icon="clipboard-document-check">
                <ul class="divide-y divide-slate-100">
                    @foreach ($detail['readiness']['checks'] as $check)
                        <li class="flex items-start gap-3 py-2 text-sm">
                            <x-ui.badge :status="$check['status'] === 'pass' ? 'done' : ($check['status'] === 'warning' ? 'pending' : 'failed')">{{ $check['status'] }}</x-ui.badge>
                            <div><p class="font-medium">{{ str_replace('_', ' ', ucfirst($check['check'])) }}</p><p class="muted">{{ $check['message'] }}</p></div>
                        </li>
                    @endforeach
                </ul>
            </x-ui.card>
        @elseif ($tab === 'basics')
            <x-ui.card title="Details" icon="pencil-square">
                <form wire:submit="saveBasics" class="grid gap-4 sm:grid-cols-2">
                    <x-ui.field label="Name" for="basics.name"><input wire:model="basics.name" class="form-input"></x-ui.field>
                    <x-ui.field label="Currency" for="basics.currency_code" hint="Locked once an active country has organizations."><select wire:model="basics.currency_code" class="form-select">@foreach ($currencies as $cur)<option value="{{ $cur }}">{{ $cur }}</option>@endforeach</select></x-ui.field>
                    <x-ui.field label="Time zone" for="basics.timezone"><input wire:model="basics.timezone" class="form-input"></x-ui.field>
                    <x-ui.field label="Default locale" for="basics.default_locale"><input wire:model="basics.default_locale" class="form-input"></x-ui.field>
                    <x-ui.field label="Phone code" for="basics.phone_code"><input wire:model="basics.phone_code" class="form-input" placeholder="+234"></x-ui.field>
                    <div class="flex items-end justify-end"><button class="btn-primary">Save details</button></div>
                </form>
            </x-ui.card>
        @elseif ($tab === 'profile')
            <x-ui.card title="Country profile" icon="adjustments-horizontal">
                <p class="mb-3 text-sm text-slate-600">Formats, phone and address rules, tax identity, verification documents and identifiers per organization type, regulatory settings, payment providers and data residency. Lists are replaced, not merged.</p>
                <form wire:submit="saveSettings" class="space-y-3">
                    <x-ui.field for="settingsJson"><textarea wire:model="settingsJson" rows="22" class="form-input font-mono text-xs" spellcheck="false"></textarea></x-ui.field>
                    @foreach ($errors->getMessages() as $key => $messages)
                        @if (str_starts_with($key, 'settings.'))<p class="text-xs text-rose-600"><span class="font-mono">{{ \Illuminate\Support\Str::after($key, 'settings.settings.') }}</span>: {{ $messages[0] }}</p>@endif
                    @endforeach
                    <div class="flex justify-end"><button class="btn-primary">Save profile</button></div>
                </form>
            </x-ui.card>
        @elseif ($tab === 'reviews')
            <x-ui.card title="Go-live reviews" icon="check-badge">
                <p class="mb-3 text-sm text-slate-600">Tax, regulatory and legal reviews must be completed, with a reference, before the country goes live.</p>
                <form wire:submit="saveReviews" class="space-y-4">
                    @foreach ($reviews as $area => $r)
                        <div class="grid gap-3 rounded-lg border border-slate-200 p-3 sm:grid-cols-[8rem_10rem_1fr_1fr]" wire:key="rv-{{ $area }}">
                            <p class="pt-2 font-semibold capitalize">{{ $area }}</p>
                            <x-ui.field label="Status" for="reviews.{{ $area }}.status"><select wire:model="reviews.{{ $area }}.status" class="form-select"><option value="pending">Pending</option><option value="partial">Partial</option><option value="completed">Completed</option></select></x-ui.field>
                            <x-ui.field label="Reference" for="reviews.{{ $area }}.reference"><input wire:model="reviews.{{ $area }}.reference" class="form-input" placeholder="Opinion, document or ticket"></x-ui.field>
                            <x-ui.field label="Notes" for="reviews.{{ $area }}.notes"><input wire:model="reviews.{{ $area }}.notes" class="form-input"></x-ui.field>
                        </div>
                    @endforeach
                    <div class="flex justify-end"><button class="btn-primary">Save reviews</button></div>
                </form>
            </x-ui.card>
        @elseif ($tab === 'tax')
            <x-ui.card title="Tax rates" icon="receipt-percent">
                <x-slot:actions><button type="button" class="btn-primary btn-sm" wire:click="startTaxRate">Add rate</button></x-slot:actions>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead><tr><th>Category</th><th>Name</th><th class="num">Rate</th><th>From</th><th>To</th><th>Source</th></tr></thead>
                        <tbody>
                            @forelse ($detail['tax_rates'] as $t)
                                <tr><td class="font-mono text-xs">{{ $t['category_code'] }}</td><td>{{ $t['name'] }}</td><td class="num">{{ rtrim(rtrim((string) $t['rate'], '0'), '.') }}%</td>
                                    <td>{{ fmt()->date($t['effective_from']) }}</td><td>{{ $t['effective_to'] ? fmt()->date($t['effective_to']) : 'Open' }}</td><td class="text-xs">{{ $t['source'] }}</td></tr>
                            @empty
                                <x-ui.empty colspan="6" title="No tax rates"/>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-ui.card>
        @else
            <div class="space-y-4">
                @forelse ($detail['regulators'] as $reg)
                    <x-ui.card :title="$reg['code'].' · '.$reg['name']" icon="building-library" wire:key="reg-{{ $reg['code'] }}">
                        <x-slot:actions><button type="button" class="btn-light btn-sm" wire:click="startIdentifierType('{{ $reg['code'] }}')">Add identifier type</button></x-slot:actions>
                        <div class="table-wrap">
                            <table class="data-table">
                                <thead><tr><th>Type</th><th>Name</th><th>Applies to</th><th>Format</th><th>Status</th></tr></thead>
                                <tbody>
                                    @forelse ($reg['identifier_types'] as $it)
                                        <tr><td class="font-mono text-xs">{{ $it['identifier_type'] }}</td><td>{{ $it['name'] }}</td><td>{{ implode(', ', (array) $it['subject_types']) }}</td>
                                            <td class="font-mono text-xs">{{ $it['format_pattern'] ?? '—' }}@if ($it['format_example'])<span class="muted block">e.g. {{ $it['format_example'] }}</span>@endif</td>
                                            <td><x-ui.badge :status="$it['is_active'] ? 'active' : 'inactive'">{{ $it['is_active'] ? 'Active' : 'Inactive' }}</x-ui.badge></td></tr>
                                    @empty
                                        <x-ui.empty colspan="5" title="No identifier types"/>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </x-ui.card>
                @empty
                    <x-ui.card><p class="text-sm text-slate-500">No regulatory authority is configured for this country yet.</p></x-ui.card>
                @endforelse
            </div>
        @endif
    @endif

    <x-ui.modal wire:model="taxRate" title="Add tax rate" size="md">
        @if ($taxRate)
            <form wire:submit="addTaxRate" id="tax-form" class="grid gap-4 sm:grid-cols-2">
                <x-ui.field label="Category code" for="taxRate.category_code" hint="Upper case, e.g. STANDARD"><input wire:model="taxRate.category_code" class="form-input font-mono uppercase"></x-ui.field>
                <x-ui.field label="Name" for="taxRate.name"><input wire:model="taxRate.name" class="form-input"></x-ui.field>
                <x-ui.field label="Rate (%)" for="taxRate.rate"><input wire:model="taxRate.rate" type="number" step="0.0001" class="form-input"></x-ui.field>
                <x-ui.field label="Effective from" for="taxRate.effective_from"><input wire:model="taxRate.effective_from" type="date" class="form-input"></x-ui.field>
                <div class="sm:col-span-2"><x-ui.field label="Source" for="taxRate.source" hint="The law, gazette or circular"><input wire:model="taxRate.source" class="form-input"></x-ui.field></div>
            </form>
        @endif
        <x-slot:footer><button type="button" class="btn-light" @click="open = null">Cancel</button><button type="submit" form="tax-form" class="btn-primary">Add rate</button></x-slot:footer>
    </x-ui.modal>

    <x-ui.modal wire:model="identifierType" title="Identifier type" size="md">
        @if ($identifierType)
            <form wire:submit="saveIdentifierType" id="it-form" class="grid gap-4 sm:grid-cols-2">
                <x-ui.field label="Code" for="identifierType.identifier_type" hint="Lower case, e.g. nafdac_number"><input wire:model="identifierType.identifier_type" class="form-input font-mono"></x-ui.field>
                <x-ui.field label="Name" for="identifierType.name"><input wire:model="identifierType.name" class="form-input"></x-ui.field>
                <div class="sm:col-span-2"><x-ui.field label="Applies to" for="identifierType.subject_types">
                    <div class="flex gap-4">@foreach (['product', 'organization', 'manufacturer'] as $s)<label class="form-check"><input type="checkbox" wire:model="identifierType.subject_types" value="{{ $s }}"> {{ ucfirst($s) }}</label>@endforeach</div>
                </x-ui.field></div>
                <x-ui.field label="Format pattern" for="identifierType.format_pattern" hint="Regular expression, optional"><input wire:model="identifierType.format_pattern" class="form-input font-mono"></x-ui.field>
                <x-ui.field label="Example" for="identifierType.format_example"><input wire:model="identifierType.format_example" class="form-input font-mono"></x-ui.field>
                <x-ui.field label="Source" for="identifierType.source"><input wire:model="identifierType.source" class="form-input"></x-ui.field>
                <label class="form-check self-end"><input type="checkbox" wire:model="identifierType.is_active"> Active</label>
            </form>
        @endif
        <x-slot:footer><button type="button" class="btn-light" @click="open = null">Cancel</button><button type="submit" form="it-form" class="btn-primary">Save</button></x-slot:footer>
    </x-ui.modal>

    <x-ui.modal wire:model="deactivating" title="Deactivate country" size="sm">
        @if ($deactivating)<form wire:submit="deactivate" id="deact-form"><x-ui.field label="Reason" for="deactivating.reason"><textarea wire:model="deactivating.reason" rows="3" class="form-input"></textarea></x-ui.field><p class="mt-2 text-xs text-slate-500">New registrations stop; existing organizations keep working.</p></form>@endif
        <x-slot:footer><button type="button" class="btn-light" @click="open = null">Cancel</button><button type="submit" form="deact-form" class="btn-danger">Deactivate</button></x-slot:footer>
    </x-ui.modal>
</div>

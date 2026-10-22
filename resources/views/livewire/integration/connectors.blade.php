<div>
    <x-ui.toolbar>
        <p class="text-sm text-slate-600">Each connected system gets its own key and mappings. The Documentation menu has the step-by-step integration guide.</p>
        <x-slot:actions>@if ($canManage)<button type="button" class="btn-primary" wire:click="create"><x-heroicon-o-plus class="size-4"/>New connector</button>@endif</x-slot:actions>
    </x-ui.toolbar>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @forelse ($connectors as $c)
            <button type="button" wire:key="c-{{ $c['id'] }}" wire:click="open('{{ $c['id'] }}')" class="card p-5 text-left transition hover:shadow-md">
                <div class="flex items-start justify-between gap-2">
                    <div><p class="font-mono text-xs text-gold-600">{{ $c['code'] }}</p><h3 class="font-semibold text-navy-950">{{ $c['name'] }}</h3><p class="text-xs text-slate-500">{{ $c['system_type_label'] }} · {{ str_replace('_', ' ', $c['inbound_transport']) }}</p></div>
                    <x-ui.badge :status="$c['status']"/>
                </div>
                <p class="mt-3 text-xs text-slate-500">Key {{ $c['inbound_key']['issued'] ? $c['inbound_key']['prefix'].'…' : 'not issued' }} · signing {{ $c['signing_enabled'] ? 'on' : 'off' }}</p>
                <p class="text-xs text-slate-500">Last message {{ $c['last_message_at'] ? fmt()->dateTime($c['last_message_at']) : 'never' }}</p>
                @if ($c['last_error'])<p class="mt-2 truncate text-xs text-rose-700">{{ $c['last_error'] }}</p>@endif
            </button>
        @empty
            <x-ui.card class="md:col-span-2 xl:col-span-3"><x-ui.empty icon="puzzle-piece" title="No connectors yet">Register the ERP or partner system that will send you data.</x-ui.empty></x-ui.card>
        @endforelse
    </div>

    <x-ui.modal wire:model="openId" :title="$detail['name'] ?? 'Connector'" size="xl">
        @if ($detail)
            @if ($secret)
                <div class="mb-4 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900" role="alert">
                    <p class="font-semibold">{{ $secret['label'] }}</p>
                    <p class="mt-1 break-all rounded bg-white px-3 py-2 font-mono text-xs text-navy-950" x-data x-on:click="navigator.clipboard?.writeText($el.textContent.trim())" title="Click to copy">{{ $secret['value'] }}</p>
                    <p class="mt-1 text-xs">{{ $secret['notice'] }}</p>
                </div>
            @endif
            <x-ui.tabs :current="$tab" :tabs="['overview' => 'Overview', 'mappings' => 'Mappings', 'files' => 'Files and exports', 'reconciliation' => 'Reconciliation']"/>

            @if ($tab === 'overview')
                <x-ui.dl :cols="3" :items="['Code' => $detail['code'], 'System' => $detail['system_type_label'].' '.($detail['system_version'] ?? ''), 'Transport' => str_replace('_', ' ', $detail['inbound_transport']),
                    'Status' => $detail['status'], 'Rate limit' => $detail['rate_limit_per_minute'].' / min', 'Deployment' => $detail['deployment_model'],
                    'Inbound key' => $detail['inbound_key']['issued'] ? $detail['inbound_key']['prefix'].'… (rotated '.fmt()->dateTime($detail['inbound_key']['rotated_at']).')' : 'Not issued',
                    'Request signing' => $detail['signing_enabled'] ? 'Required' : 'Off', 'Credentials stored' => implode(', ', $detail['credentials_set']) ?: 'None',
                    'Last processed' => $detail['last_processed_at'] ? fmt()->dateTime($detail['last_processed_at']) : null, 'Last failure' => $detail['last_failure_at'] ? fmt()->dateTime($detail['last_failure_at']) : null, 'Last error' => $detail['last_error']]"/>
                @if ($detail['interface_notes'])<p class="mt-3 whitespace-pre-line text-sm text-slate-600">{{ $detail['interface_notes'] }}</p>@endif
                @if ($canManage)
                    <div class="mt-5 flex flex-wrap gap-2">
                        <button type="button" class="btn-light btn-sm" wire:click="edit(@js($detail))">Edit</button>
                        <button type="button" class="btn-light btn-sm" wire:click="rotateKey" wire:confirm="{{ $detail['inbound_key']['issued'] ? 'Issue a new key? The current key stops working at once.' : 'Issue the inbound key?' }}">{{ $detail['inbound_key']['issued'] ? 'Rotate key' : 'Issue key' }}</button>
                        <button type="button" class="btn-light btn-sm" wire:click="setSigning({{ $detail['signing_enabled'] ? 'false' : 'true' }})">{{ $detail['signing_enabled'] ? 'Turn off signing' : 'Require signing' }}</button>
                        @if ($detail['status'] !== 'active')<button type="button" class="btn-primary btn-sm" wire:click="setStatus('activate')">Activate</button>@endif
                        @if ($detail['status'] === 'active')<button type="button" class="btn-light btn-sm" wire:click="setStatus('pause')">Pause</button>@endif
                        @if ($detail['status'] !== 'disabled')<button type="button" class="btn-danger btn-sm" wire:click="setStatus('disable')" wire:confirm="Disable this connector? It stops accepting messages.">Disable</button>@endif
                    </div>
                    <form wire:submit="saveCredentials" class="mt-5 rounded-lg border border-slate-200 p-4">
                        <p class="mb-2 text-sm font-semibold text-navy-950">Outbound credentials <span class="font-normal text-slate-500">(SFTP password, client secret…; stored encrypted, never shown again)</span></p>
                        @foreach ($credentials as $i => $c)
                            <div class="mb-2 grid gap-2 sm:grid-cols-2"><input wire:model="credentials.{{ $i }}.name" class="form-input py-2" placeholder="Name, e.g. sftp_password"><input type="password" wire:model="credentials.{{ $i }}.value" class="form-input py-2" placeholder="Value (empty removes it)"></div>
                        @endforeach
                        <div class="flex justify-between"><button type="button" class="link text-xs" wire:click="addCredential">+ Another</button><button class="btn-primary btn-sm">Save credentials</button></div>
                    </form>
                @endif
            @elseif ($tab === 'mappings')
                <div class="mb-3 flex flex-wrap items-end gap-2">
                    <x-ui.field label="Entity" for="entity"><select id="entity" wire:model.live="entity" class="form-select w-48">@foreach ($canonical as $e)@if ($e['ingestible'])<option value="{{ $e['entity'] }}">{{ str_replace('_', ' ', $e['entity']) }}</option>@endif @endforeach</select></x-ui.field>
                    @if ($templates->isNotEmpty() && $canManage)
                        <x-ui.field label="Start from a template" for="template"><select id="template" wire:model="template" class="form-select w-64"><option value="">Choose…</option>@foreach ($templates as $t)<option value="{{ $t['code'] }}">{{ $t['label'] }}</option>@endforeach</select></x-ui.field>
                        <button type="button" class="btn-light" wire:click="applyTemplate">Apply</button>
                    @endif
                </div>
                @php $fields = collect(collect($canonical)->firstWhere('entity', $entity)['fields'] ?? []); @endphp
                @error('mappings.mappings') <p class="mb-2 text-xs text-rose-600">{{ $message }}</p> @enderror
                <div class="space-y-2">
                    @foreach ($mappingRows ?? [] as $i => $m)
                        <div class="grid items-start gap-2 md:grid-cols-[12rem_minmax(0,1fr)_9rem_9rem_auto]" wire:key="m-{{ $i }}">
                            <select wire:model="mappingRows.{{ $i }}.target_field" class="form-select py-2" aria-label="Platform field"><option value="">Platform field…</option>@foreach ($fields as $f)<option value="{{ $f['name'] }}">{{ $f['name'] }}{{ $f['required'] === true ? ' *' : '' }}</option>@endforeach</select>
                            <input wire:model="mappingRows.{{ $i }}.source_path" class="form-input py-2 font-mono text-xs" placeholder="Source path, e.g. MATNR or item.batch" aria-label="Source path">
                            <select wire:model="mappingRows.{{ $i }}.transform" class="form-select py-2" aria-label="Transform"><option value="">No transform</option>@foreach ($transforms as $t)<option value="{{ $t }}">{{ str_replace('_', ' ', $t) }}</option>@endforeach</select>
                            <input wire:model="mappingRows.{{ $i }}.default_value" class="form-input py-2" placeholder="Default" aria-label="Default">
                            @if ($canManage)<button type="button" class="p-2 text-slate-400 hover:text-rose-600" wire:click="removeMapping({{ $i }})" aria-label="Remove"><x-heroicon-o-trash class="size-4"/></button>@endif
                        </div>
                    @endforeach
                </div>
                @if ($canManage)<div class="mt-3 flex justify-between"><button type="button" class="link text-sm" wire:click="addMapping">+ Add mapping</button><button type="button" class="btn-primary btn-sm" wire:click="saveMappings">Save mappings</button></div>@endif
                <div class="mt-5 rounded-lg border border-slate-200 p-4">
                    <p class="mb-2 text-sm font-semibold text-navy-950">Try a sample record</p>
                    <textarea wire:model="sample" rows="4" class="form-input font-mono text-xs" placeholder='{"MATNR": "000000000000012345", "CHARG": "B123", "QTY": "10"}'></textarea>
                    @error('sample') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    <div class="mt-2 flex justify-end"><button type="button" class="btn-light btn-sm" wire:click="runPreview">Preview mapping</button></div>
                    @foreach ($preview ?? [] as $p)
                        <div class="mt-3 rounded-lg p-3 text-xs {{ $p['valid'] ? 'bg-emerald-50 text-emerald-900' : 'bg-rose-50 text-rose-900' }}">
                            <p class="font-semibold">{{ $p['valid'] ? 'Valid' : 'Not valid' }}</p>
                            @if ($p['canonical'])<pre class="mt-1 overflow-x-auto">{{ json_encode($p['canonical'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>@endif
                            @foreach ($p['errors'] as $e)<p>{{ $e['field'] ?? ($e['code'] ?? '') }}: {{ is_array($e['messages'] ?? null) ? implode(' ', $e['messages']) : ($e['message'] ?? '') }} {{ $e['hint'] ?? '' }}</p>@endforeach
                        </div>
                    @endforeach
                </div>
            @elseif ($tab === 'files')
                @if ($canManage)
                    <form wire:submit="uploadFile" class="flex flex-wrap items-end gap-2 rounded-lg border border-dashed border-slate-300 bg-slate-50 p-3">
                        <x-ui.field label="Entity" for="upload.entity_type"><select wire:model="fileEntity" class="form-select w-44">@foreach ($canonical as $e)@if ($e['ingestible'])<option value="{{ $e['entity'] }}">{{ str_replace('_', ' ', $e['entity']) }}</option>@endif @endforeach</select></x-ui.field>
                        <x-ui.field label="Format" for="upload.format"><select wire:model="fileFormat" class="form-select w-32"><option value="">From name</option>@foreach ($formats as $f)<option value="{{ $f }}">{{ strtoupper($f) }}</option>@endforeach</select></x-ui.field>
                        <x-ui.field label="File" for="file" class="min-w-0 flex-1"><input type="file" wire:model="file" class="form-input py-1.5">@error('upload.file') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror</x-ui.field>
                        <button class="btn-primary" wire:loading.attr="disabled" wire:target="file,uploadFile">Upload</button>
                    </form>
                    @if ($detail['inbound_transport'] === 'sftp')<button type="button" class="btn-light btn-sm mt-3" wire:click="pollSftp">Check the SFTP folder now</button>@endif
                    @if ($fileResult)
                        <pre class="mt-3 max-h-60 overflow-auto rounded-lg bg-slate-50 p-3 text-xs">{{ json_encode($fileResult['run'] ?? $fileResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                        <a href="{{ route('integration.runs') }}" class="link mt-1 inline-block text-xs">Follow the run</a>
                    @endif
                    <div class="mt-5"><p class="mb-2 text-sm font-semibold text-navy-950">Export for this system</p>
                        <div class="flex gap-2">@foreach ($datasets as $d)<button type="button" class="btn-light btn-sm" wire:click="export('{{ $d }}')"><x-heroicon-o-arrow-down-tray class="size-4"/>{{ ucfirst($d) }} CSV</button>@endforeach</div></div>
                @else
                    <p class="text-sm text-slate-500">Uploading files and exports need integration management permission.</p>
                @endif
            @else
                @php $inv = $reconciliation['inventory'] ?? null; $refs = $reconciliation['references'] ?? null; @endphp
                @if ($refs)
                    <div class="grid gap-3 sm:grid-cols-4">
                        <x-ui.stat label="Catalogue items" :value="$refs['products']['catalogue_items']"/>
                        <x-ui.stat label="Mapped" :value="$refs['products']['mapped']" tone="green"/>
                        <x-ui.stat label="Quarantined messages" :value="collect($refs['open_quarantine_by_code'] ?? [])->sum()" tone="amber"/>
                        <x-ui.stat label="Dead-lettered" :value="$refs['dead_lettered']" tone="red"/>
                    </div>
                    @if (count($refs['products']['unmapped_catalogue_items']))<p class="mt-3 text-sm">Not mapped to this system: {{ collect($refs['products']['unmapped_catalogue_items'])->pluck('sku')->implode(', ') }}</p>@endif
                    <p class="mt-2 text-sm">Warehouses: {{ collect($refs['warehouses'])->map(fn ($w) => $w['code'].($w['mapped'] ? ' ✓' : ' (not mapped)'))->implode(', ') }}</p>
                @endif
                @if ($inv)
                    <h3 class="mb-2 mt-5 text-sm font-semibold text-navy-950">Stock: source system against platform</h3>
                    <p class="mb-2 text-xs text-slate-500">{{ collect($inv['summary'])->map(fn ($v, $k) => str_replace('_', ' ', $k).': '.$v)->implode(' · ') }}</p>
                    <div class="table-wrap max-h-72 rounded-lg border border-slate-200">
                        <table class="data-table">
                            <thead><tr><th>Batch</th><th class="num">Source</th><th class="num">Platform</th><th class="num">Difference</th><th>Status</th></tr></thead>
                            <tbody>@forelse ($inv['rows'] as $r)<tr><td class="font-mono text-xs">{{ $r['batch_number'] }}</td><td class="num">{{ $r['source_quantity'] ?? '—' }}</td><td class="num">{{ $r['platform_quantity'] }}</td><td class="num">{{ $r['difference'] }}</td><td><x-ui.badge :status="$r['status'] === 'match' ? 'ok' : 'warning'">{{ str_replace('_', ' ', $r['status']) }}</x-ui.badge></td></tr>@empty<x-ui.empty colspan="5" title="No stock reported by this system yet"/>@endforelse</tbody>
                        </table>
                    </div>
                @endif
            @endif
        @endif
    </x-ui.modal>

    <x-ui.modal wire:model="form" :title="($form['id'] ?? null) ? 'Edit connector' : 'New connector'" size="lg">
        @if ($form)
            <form wire:submit="save" id="con-form" class="grid gap-4 sm:grid-cols-2">
                <x-ui.field label="Code" for="form.code"><input id="form.code" wire:model="form.code" class="form-input font-mono uppercase" @disabled($form['id'])></x-ui.field>
                <x-ui.field label="Name" for="form.name"><input id="form.name" wire:model="form.name" class="form-input"></x-ui.field>
                <x-ui.field label="System" for="form.system_type"><select id="form.system_type" wire:model="form.system_type" class="form-select">@foreach ($systemTypes as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></x-ui.field>
                <x-ui.field label="Version" for="form.system_version"><input id="form.system_version" wire:model="form.system_version" class="form-input"></x-ui.field>
                <x-ui.field label="How it sends data" for="form.inbound_transport"><select id="form.inbound_transport" wire:model="form.inbound_transport" class="form-select">@foreach ($transports as $t)<option value="{{ $t }}">{{ str_replace('_', ' ', $t) }}</option>@endforeach</select></x-ui.field>
                <x-ui.field label="Deployment" for="form.deployment_model"><select id="form.deployment_model" wire:model="form.deployment_model" class="form-select"><option value="">—</option><option value="cloud">Cloud</option><option value="on_premise">On premise</option><option value="hybrid">Hybrid</option></select></x-ui.field>
                <x-ui.field label="Rate limit (per minute)" for="form.rate_limit_per_minute"><input id="form.rate_limit_per_minute" type="number" wire:model="form.rate_limit_per_minute" class="form-input"></x-ui.field>
                <x-ui.field label="Interface notes" for="form.interface_notes" class="sm:col-span-2"><textarea id="form.interface_notes" wire:model="form.interface_notes" rows="3" class="form-input"></textarea></x-ui.field>
            </form>
        @endif
        <x-slot:footer><button type="button" class="btn-light" @click="open = null">Cancel</button><button type="submit" form="con-form" class="btn-primary">Save</button></x-slot:footer>
    </x-ui.modal>
</div>

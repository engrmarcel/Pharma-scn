<div class="space-y-6">
    {{-- Summary --}}
    <div class="card flex flex-wrap items-center justify-between gap-4 p-5">
        <div class="flex items-center gap-4">
            <span class="grid size-14 place-items-center rounded-2xl bg-navy-50 text-[var(--brand-primary)] ring-1 ring-navy-100"><x-heroicon-o-building-office-2 class="size-7"/></span>
            <div>
                <h2 class="text-xl font-semibold text-navy-950">{{ $org['name'] }}</h2>
                <p class="text-sm text-slate-500">{{ \App\Domain\Organizations\Enums\OrganizationType::tryFrom($org['type'])?->label() }} · {{ $org['country_code'] }} · {{ $org['locale']['currency_code'] }}</p>
                <div class="mt-2 flex flex-wrap gap-2">
                    <x-ui.badge :status="$org['status']"/>
                    <x-ui.badge :status="$org['platform_verification']['status']">Platform verification: {{ str_replace('_', ' ', $org['platform_verification']['status']) }}</x-ui.badge>
                </div>
            </div>
        </div>
        @if ($canManage)
            <div class="flex flex-wrap gap-2">
                <button type="button" class="btn-light" wire:click="edit"><x-heroicon-o-pencil-square class="size-4"/>Edit profile</button>
                @if (in_array($org['platform_verification']['status'], ['unverified', 'changes_requested', 'rejected'], true))
                    <button type="button" class="btn-primary" wire:click="submitForVerification" wire:loading.attr="disabled"
                            wire:confirm="Submit your documents and details for platform verification?">
                        <x-heroicon-o-paper-airplane class="size-4"/>Submit for verification
                    </button>
                @endif
            </div>
        @endif
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <x-ui.card title="Details" icon="identification">
                <x-ui.dl :items="[
                    'Legal name' => $org['legal_name'],
                    ($requirements['labels']['business_registration_number'] ?? null) ?: 'Business registration no.' => $org['business_registration_number'],
                    ($requirements['labels']['tax_identification_number'] ?? null) ?: 'Tax ID' => $org['tax_identification_number'],
                    'Email' => $org['email'],
                    'Phone' => $org['phone'],
                    'Website' => $org['website_url'],
                    'Address' => collect([$org['address']['line1'], $org['address']['line2'], $org['address']['city'], $org['address']['state_region'], $org['address']['postal_code']])->filter()->implode(', '),
                    'Time zone · locale' => $org['locale']['timezone'].' · '.$org['locale']['locale'],
                    'Modules' => collect($org['modules'] ?? [])->map(fn ($m) => config('platform.modules.'.$m, $m))->implode(', '),
                    'Member since' => fmt()->date($org['created_at']),
                ]"/>
            </x-ui.card>

            <x-ui.card title="Licences and registrations" icon="document-check">
                <x-slot:actions>
                    @if ($canManage)<button type="button" class="btn-light btn-sm" wire:click="addIdentifier"><x-heroicon-o-plus class="size-4"/>Add</button>@endif
                </x-slot:actions>
                <div class="table-wrap -m-5">
                    <table class="data-table">
                        <thead><tr><th>Authority</th><th>Type</th><th>Number</th><th>Declared</th><th>Expiry</th><th>Platform check</th></tr></thead>
                        <tbody>
                            @forelse ($identifiers as $i)
                                <tr wire:key="id-{{ $i['id'] }}">
                                    <td class="font-medium">{{ $i['authority'] }}</td>
                                    <td>{{ str_replace('_', ' ', $i['identifier_type']) }}</td>
                                    <td class="font-mono text-xs">{{ $i['value'] }}</td>
                                    <td><x-ui.badge :status="$i['declared']['status']"/></td>
                                    <td>{{ $i['declared']['expiry_date'] ? fmt()->date($i['declared']['expiry_date']) : '—' }}</td>
                                    <td><x-ui.badge :status="$i['platform_verification']['status']"/></td>
                                </tr>
                            @empty
                                <x-ui.empty colspan="6" icon="document-check" title="No licences recorded">Record the licence and registration numbers your regulator issued.</x-ui.empty>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-ui.card>

            <x-ui.card title="Documents" icon="paper-clip">
                @if ($canManage)
                    <form wire:submit="upload" class="mb-4 flex flex-wrap items-end gap-3 rounded-lg border border-dashed border-slate-300 bg-slate-50 p-3">
                        <x-ui.field label="Category" for="documentCategory" class="w-56">
                            <select id="documentCategory" wire:model="documentCategory" class="form-select">
                                @foreach (['business_registration', 'premises_licence', 'pharmacist_licence', 'regulatory_licence', 'tax_certificate', 'other'] as $c)
                                    <option value="{{ $c }}">{{ ucfirst(str_replace('_', ' ', $c)) }}</option>
                                @endforeach
                            </select>
                        </x-ui.field>
                        <x-ui.field label="File (PDF or image)" for="document" error="document" class="min-w-0 flex-1">
                            <input id="document" type="file" wire:model="document" accept=".pdf,.jpg,.jpeg,.png,.webp" class="form-input py-1.5">
                            @error('upload.file') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                        </x-ui.field>
                        <button class="btn-primary" wire:loading.attr="disabled" wire:target="document,upload"><x-heroicon-o-arrow-up-tray class="size-4"/>Upload</button>
                    </form>
                @endif
                <ul class="divide-y divide-slate-100">
                    @forelse ($documents as $d)
                        <li class="flex items-center justify-between gap-3 py-2.5 text-sm" wire:key="doc-{{ $d['id'] }}">
                            <span class="flex min-w-0 items-center gap-3">
                                <x-heroicon-o-document class="size-5 shrink-0 text-slate-400"/>
                                <span class="min-w-0">
                                    <span class="block truncate font-medium text-navy-900">{{ $d['original_name'] }}</span>
                                    <span class="muted">{{ ucfirst(str_replace('_', ' ', $d['category'])) }} · {{ number_format($d['size_bytes'] / 1024) }} KB · {{ fmt()->dateTime($d['created_at']) }}</span>
                                </span>
                            </span>
                            <span class="flex items-center gap-2">
                                <x-ui.badge :status="$d['scan_status']">scan: {{ $d['scan_status'] }}</x-ui.badge>
                                <button type="button" class="btn-light btn-sm" wire:click="download('{{ $d['id'] }}', @js($d['original_name']))"><x-heroicon-o-arrow-down-tray class="size-4"/></button>
                            </span>
                        </li>
                    @empty
                        <li><x-ui.empty icon="paper-clip" title="No documents uploaded"/></li>
                    @endforelse
                </ul>
            </x-ui.card>
        </div>

        <div class="space-y-6">
            @if ($requirements)
                <x-ui.card title="Verification checklist" icon="clipboard-document-check">
                    <p class="mb-3 text-sm {{ $requirements['ready'] ? 'text-emerald-700' : 'text-slate-600' }}">
                        {{ $requirements['ready'] ? 'Everything your country requires is in place.' : 'What '.$requirements['country_code'].' requires before verification:' }}
                    </p>
                    <ul class="space-y-2 text-sm">
                        @foreach ($requirements['documents'] as $r)
                            <li class="flex items-center gap-2">
                                @if ($r['present'])<x-heroicon-s-check-circle class="size-5 text-emerald-500"/>@else<x-heroicon-o-minus-circle class="size-5 text-slate-300"/>@endif
                                Document: {{ str_replace('_', ' ', $r['category']) }}
                            </li>
                        @endforeach
                        @foreach ($requirements['identifiers'] as $r)
                            <li class="flex items-center gap-2">
                                @if ($r['present'])<x-heroicon-s-check-circle class="size-5 text-emerald-500"/>@else<x-heroicon-o-minus-circle class="size-5 text-slate-300"/>@endif
                                <span class="flex-1">{{ $r['name'] }}</span>
                                @if (! $r['present'] && $canManage)
                                    <button type="button" class="link text-xs" wire:click="addIdentifier('{{ $r['authority'] }}', '{{ $r['identifier_type'] }}')">Add</button>
                                @endif
                            </li>
                        @endforeach
                        @foreach ($requirements['profile_fields'] as $r)
                            <li class="flex items-center gap-2">
                                @if ($r['present'])<x-heroicon-s-check-circle class="size-5 text-emerald-500"/>@else<x-heroicon-o-minus-circle class="size-5 text-slate-300"/>@endif
                                {{ ucfirst($r['label']) }}
                            </li>
                        @endforeach
                        @if (! $requirements['any_document'])
                            <li class="flex items-center gap-2"><x-heroicon-o-minus-circle class="size-5 text-slate-300"/>At least one document</li>
                        @endif
                    </ul>
                    <p class="mt-4 text-xs text-slate-500">Platform verification is the platform's own review. It is not regulatory approval.</p>
                </x-ui.card>
            @endif

            <x-ui.card title="Payment methods" icon="credit-card">
                <ul class="space-y-2 text-sm">
                    @foreach ($paymentMethods as $m)
                        <li class="flex items-center justify-between gap-2"><span>{{ $m['name'] }}</span><span class="muted">{{ str_replace('_', ' ', $m['confirmation']) }}</span></li>
                    @endforeach
                </ul>
            </x-ui.card>

            <x-ui.card title="Security" icon="lock-closed">
                <p class="text-sm text-slate-600">Require two-factor sign-in for every member of this organization.</p>
                <div class="mt-3 flex items-center justify-between">
                    <x-ui.badge :tone="$requireMfa ? 'green' : 'slate'">{{ $requireMfa ? 'Required' : 'Not required' }}</x-ui.badge>
                    @if ($canManage)
                        <button type="button" class="btn-light btn-sm" wire:click="setRequireMfa({{ $requireMfa ? 'false' : 'true' }})">{{ $requireMfa ? 'Stop requiring' : 'Require two-factor' }}</button>
                    @endif
                </div>
            </x-ui.card>
        </div>
    </div>

    {{-- Edit profile --}}
    <x-ui.modal wire:model="form" title="Edit organization profile" size="xl">
        @if ($form)
            <form wire:submit="save" id="profile-form" class="grid gap-4 sm:grid-cols-2">
                @foreach ([
                    'name' => 'Trading name', 'legal_name' => 'Legal name',
                    'business_registration_number' => $requirements['labels']['business_registration_number'] ?? 'Business registration no.',
                    'tax_identification_number' => $requirements['labels']['tax_identification_number'] ?? 'Tax ID',
                    'email' => 'Email', 'phone' => 'Phone', 'website_url' => 'Website', 'gln' => 'GLN (13 digits)',
                    'address_line1' => 'Address line 1', 'address_line2' => 'Address line 2', 'city' => 'City',
                    'state_region' => $requirements['labels']['state_region'] ?? 'State / region', 'postal_code' => 'Postal code',
                    'timezone' => 'Time zone', 'locale' => 'Locale (e.g. en_NG)',
                ] as $field => $label)
                    <x-ui.field :label="$label" :for="'form.'.$field">
                        <input id="form.{{ $field }}" type="text" wire:model="form.{{ $field }}" class="form-input">
                    </x-ui.field>
                @endforeach
            </form>
        @endif
        <x-slot:footer>
            <button type="button" class="btn-light" @click="open = null">Cancel</button>
            <button type="submit" form="profile-form" class="btn-primary" wire:loading.attr="disabled">Save profile</button>
        </x-slot:footer>
    </x-ui.modal>

    {{-- Add identifier --}}
    <x-ui.modal wire:model="identifier" title="Record a licence or registration" size="md">
        @if ($identifier)
            <form wire:submit="saveIdentifier" id="identifier-form" class="grid gap-4 sm:grid-cols-2">
                <x-ui.field label="Authority" for="identifier.authority_code">
                    <select id="identifier.authority_code" wire:model="identifier.authority_code" class="form-select">
                        <option value="">Choose…</option>
                        @foreach ($authorities as $a)<option value="{{ $a->code }}">{{ $a->code }} · {{ $a->name }}</option>@endforeach
                    </select>
                </x-ui.field>
                <x-ui.field label="Type" for="identifier.identifier_type">
                    <select id="identifier.identifier_type" wire:model="identifier.identifier_type" class="form-select">
                        <option value="">Choose…</option>
                        @foreach ($identifierTypes as $t)<option value="{{ $t->identifier_type }}">{{ $t->authority->code }} · {{ $t->name }}</option>@endforeach
                    </select>
                </x-ui.field>
                <x-ui.field label="Number" for="identifier.value" class="sm:col-span-2">
                    <input id="identifier.value" wire:model="identifier.value" class="form-input font-mono">
                </x-ui.field>
                <x-ui.field label="Declared status" for="identifier.declared_status">
                    <select id="identifier.declared_status" wire:model="identifier.declared_status" class="form-select">
                        @foreach (['registered', 'expired', 'suspended', 'cancelled', 'unknown'] as $s)<option value="{{ $s }}">{{ ucfirst($s) }}</option>@endforeach
                    </select>
                </x-ui.field>
                <x-ui.field label="Holder name" for="identifier.holder_name"><input id="identifier.holder_name" wire:model="identifier.holder_name" class="form-input"></x-ui.field>
                <x-ui.field label="Issued" for="identifier.registration_date"><input id="identifier.registration_date" type="date" wire:model="identifier.registration_date" class="form-input"></x-ui.field>
                <x-ui.field label="Expires" for="identifier.expiry_date"><input id="identifier.expiry_date" type="date" wire:model="identifier.expiry_date" class="form-input"></x-ui.field>
            </form>
        @endif
        <x-slot:footer>
            <button type="button" class="btn-light" @click="open = null">Cancel</button>
            <button type="submit" form="identifier-form" class="btn-primary" wire:loading.attr="disabled">Save</button>
        </x-slot:footer>
    </x-ui.modal>
</div>

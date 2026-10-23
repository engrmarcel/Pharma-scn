<div>
    <x-ui.toolbar>
        <x-ui.search placeholder="Organization name…"/>
        <x-ui.field label="Verification" for="verification"><select id="verification" wire:model.live="verification" class="form-select w-44"><option value="">Any</option>@foreach ($statuses as $s)<option value="{{ $s->value }}">{{ ucfirst(str_replace('_', ' ', $s->value)) }}</option>@endforeach</select></x-ui.field>
        <x-ui.field label="Status" for="status"><select id="status" wire:model.live="status" class="form-select w-36"><option value="">Any</option><option value="active">Active</option><option value="suspended">Suspended</option></select></x-ui.field>
        <x-ui.field label="Type" for="type"><select id="type" wire:model.live="type" class="form-select w-48"><option value="">Any</option>@foreach ($types as $t)<option value="{{ $t->value }}">{{ $t->label() }}</option>@endforeach</select></x-ui.field>
        <x-slot:actions><a href="{{ route('admin.verifications') }}" class="btn-light"><x-heroicon-o-shield-check class="size-4"/>Verification queue</a></x-slot:actions>
    </x-ui.toolbar>

    <div class="card overflow-hidden">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Organization</th><th>Type</th><th>Country</th><th>Modules</th><th>Verification</th><th>Status</th><th>Joined</th></tr></thead>
                <tbody>
                    @forelse ($rows as $o)
                        <tr wire:key="o-{{ $o['id'] }}" class="cursor-pointer" wire:click="open('{{ $o['id'] }}')">
                            <td><span class="font-medium text-navy-900">{{ $o['name'] }}</span><span class="muted block">{{ $o['email'] }}</span></td>
                            <td>{{ \App\Domain\Organizations\Enums\OrganizationType::tryFrom($o['type'])?->label() }}</td>
                            <td>{{ $o['country_code'] }}</td>
                            <td class="text-xs">{{ count($o['modules'] ?? []) }}</td>
                            <td><x-ui.badge :status="$o['platform_verification']['status']"/></td>
                            <td><x-ui.badge :status="$o['status']"/></td>
                            <td>{{ fmt()->date($o['created_at']) }}</td>
                        </tr>
                    @empty
                        <x-ui.empty colspan="7" icon="building-office-2" title="No organizations match"/>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-ui.pager :paginator="$meta"/>
    </div>

    <x-ui.modal wire:model="openId" :title="$detail['data']['name'] ?? 'Organization'" size="xl">
        @if ($detail)
            @php $o = $detail['data']; @endphp
            <div class="mb-4 flex flex-wrap gap-2"><x-ui.badge :status="$o['status']"/><x-ui.badge :status="$o['platform_verification']['status']">verification: {{ str_replace('_', ' ', $o['platform_verification']['status']) }}</x-ui.badge></div>
            <x-ui.tabs :current="$tab" :tabs="array_filter(['profile' => 'Profile', 'modules' => 'Modules', 'subscription' => $can['billing'] ? 'Subscription' : null, 'status' => $can['suspend'] ? 'Suspension' : null])"/>

            @if ($tab === 'profile')
                <x-ui.dl :cols="3" :items="['Legal name' => $o['legal_name'], 'Type' => \App\Domain\Organizations\Enums\OrganizationType::tryFrom($o['type'])?->label(), 'Country' => $o['country_code'],
                    'Registration no.' => $o['business_registration_number'], 'Tax ID' => $o['tax_identification_number'], 'Email' => $o['email'], 'Phone' => $o['phone'],
                    'Address' => collect($o['address'])->filter()->implode(', '), 'Joined' => fmt()->date($o['created_at'])]"/>
                <h3 class="mb-2 mt-5 text-sm font-semibold text-navy-950">Documents</h3>
                <ul class="divide-y divide-slate-100 rounded-lg border border-slate-200 text-sm">
                    @forelse ($detail['documents'] as $d)
                        <li class="flex items-center justify-between px-3 py-2"><span>{{ str_replace('_', ' ', $d['category']) }}: {{ $d['original_name'] }} <span class="text-xs text-slate-400">scan {{ $d['scan_status'] }}</span></span>
                            @if ($can['verify'])<button type="button" class="link text-xs" wire:click="download('{{ $d['id'] }}', @js($d['original_name']))">Download</button>@endif</li>
                    @empty
                        <li class="px-3 py-2 text-slate-500">No documents.</li>
                    @endforelse
                </ul>
                <h3 class="mb-2 mt-5 text-sm font-semibold text-navy-950">Verification history</h3>
                <ul class="space-y-1 text-xs text-slate-600">@forelse ($detail['verification_history'] as $v)<li>{{ fmt()->dateTime($v['created_at']) }} · {{ str_replace('_', ' ', $v['action']) }} ({{ $v['from_status'] }} → {{ $v['to_status'] }}) by {{ $v['actor'] ?? 'system' }}{{ $v['notes'] ? ': '.$v['notes'] : '' }}</li>@empty<li>None.</li>@endforelse</ul>
            @elseif ($tab === 'modules')
                <div class="grid gap-2 sm:grid-cols-2">
                    @foreach ($moduleNames as $m => $label)
                        <label class="flex items-center gap-2 rounded-lg border border-slate-200 p-2 text-sm"><input type="checkbox" wire:model="modules.{{ $m }}" class="form-check" @disabled(! $can['modules'])>{{ $label }}</label>
                    @endforeach
                </div>
                <p class="mt-2 text-xs text-slate-500">A module is usable only when it is switched on here and the organization's plan includes it.</p>
                @if ($can['modules'])<div class="mt-3 flex justify-end"><button type="button" class="btn-primary btn-sm" wire:click="saveModules">Save modules</button></div>@endif
            @elseif ($tab === 'subscription' && $sub)
                <div class="grid gap-4 sm:grid-cols-3">
                    <x-ui.stat label="Plan" :value="$sub['plan']['name'] ?? '—'"/>
                    <x-ui.stat label="Status" :value="str_replace('_', ' ', $sub['status'] ?? '—')" :tone="$sub['operational'] ? 'green' : 'red'"/>
                    <x-ui.stat label="Trial ends" :value="$sub['trial_ends_at'] ? fmt()->date($sub['trial_ends_at']) : '—'"/>
                </div>
                <form wire:submit="saveSubscription" class="mt-4 flex flex-wrap items-end gap-2">
                    <x-ui.field label="Plan" for="subscription.plan_code"><select wire:model="subscription.plan_code" class="form-select w-48">@foreach ($plans as $code => $name)<option value="{{ $code }}">{{ $name }}</option>@endforeach</select></x-ui.field>
                    <x-ui.field label="Status" for="subscription.status"><select wire:model="subscription.status" class="form-select w-40">@foreach (['trialing', 'active', 'past_due', 'grace', 'suspended', 'cancelled', 'expired'] as $s)<option value="{{ $s }}">{{ str_replace('_', ' ', $s) }}</option>@endforeach</select></x-ui.field>
                    <button class="btn-primary">Change</button>
                </form>
                <form wire:submit="saveOverride" class="mt-5 rounded-lg border border-slate-200 p-4">
                    <p class="mb-2 text-sm font-semibold text-navy-950">Override one entitlement for this organization</p>
                    <div class="grid gap-2 sm:grid-cols-4">
                        <x-ui.field for="override.key"><select wire:model="override.key" class="form-select py-2"><option value="">Entitlement…</option>@foreach ($entitlementKeys as $k => [$kind, $l])<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></x-ui.field>
                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="override.enabled" class="form-check">Enabled</label>
                        <input wire:model="override.limit" type="number" min="0" class="form-input py-2" placeholder="Limit (empty: none)">
                        <x-ui.field for="override.reason"><input wire:model="override.reason" class="form-input py-2" placeholder="Reason"></x-ui.field>
                    </div>
                    <div class="mt-2 flex justify-end"><button class="btn-light btn-sm">Save override</button></div>
                </form>
                <p class="mb-1 mt-4 text-xs font-semibold uppercase text-slate-400">History</p>
                <ul class="text-xs text-slate-600">@foreach ($sub['history'] as $h)<li>{{ $h['plan'] }} · {{ $h['status'] }} · {{ fmt()->date($h['starts_at']) }}{{ $h['ended_at'] ? ' – '.fmt()->date($h['ended_at']) : '' }}</li>@endforeach</ul>
            @elseif ($tab === 'status')
                <p class="text-sm text-slate-600">Suspending stops every member from working in this organization (web, app and API) until it is reactivated. Nothing is deleted.</p>
                <x-ui.field label="Reason" for="suspend.reason" class="mt-3"><textarea wire:model="reason" rows="2" class="form-input"></textarea></x-ui.field>
                <div class="mt-3 flex justify-end gap-2">
                    @if ($o['status'] === 'active')<button type="button" class="btn-danger" wire:click="suspend" wire:confirm="Suspend {{ $o['name'] }}?">Suspend</button>@else<button type="button" class="btn-primary" wire:click="reactivate">Reactivate</button>@endif
                </div>
            @endif
        @endif
    </x-ui.modal>
</div>

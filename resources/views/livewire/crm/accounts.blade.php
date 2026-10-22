<div>
    <x-ui.toolbar>
        <x-ui.search placeholder="Customer name or code…"/>
        <x-ui.field label="Tier" for="tier"><select id="tier" wire:model.live="tier" class="form-select w-28"><option value="">All</option><option>A</option><option>B</option><option>C</option></select></x-ui.field>
        <x-ui.field label="Segment" for="segment"><select id="segment" wire:model.live="segment" class="form-select w-48"><option value="">All</option>@foreach ($segments as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select></x-ui.field>
        <label class="flex items-center gap-2 pb-3 text-sm"><input type="checkbox" wire:model.live="due" class="form-check">Visit due</label>
    </x-ui.toolbar>

    <div class="card overflow-hidden">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Customer</th><th>Type</th><th>Tier</th><th>Call every</th><th>Last visit</th><th>Next due</th><th class="num">Potential</th></tr></thead>
                <tbody>
                    @forelse ($rows as $a)
                        <tr wire:key="a-{{ $a['id'] }}" class="cursor-pointer" wire:click="open('{{ $a['id'] }}')">
                            <td><span class="font-medium text-navy-900">{{ $a['name'] }}</span><span class="muted block">{{ $a['code'] }} · {{ $a['city'] }}</span></td>
                            <td>{{ str_replace('_', ' ', $a['type']) }}</td>
                            <td>@if ($a['tier'])<x-ui.badge :tone="$a['tier'] === 'A' ? 'gold' : 'blue'">{{ $a['tier'] }}</x-ui.badge>@else — @endif</td>
                            <td>{{ $a['visit_frequency_days'] ? $a['visit_frequency_days'].' days' : '—' }}</td>
                            <td>{{ $a['last_completed_visit_at'] ? fmt()->date($a['last_completed_visit_at']) : 'Never' }}</td>
                            <td class="{{ $a['visit_overdue'] ? 'font-semibold text-rose-700' : '' }}">{{ $a['next_visit_due'] ? fmt()->date($a['next_visit_due']) : '—' }}</td>
                            <td class="num">{{ $a['potential_value'] !== null ? fmt()->number($a['potential_value'], 0) : '—' }}</td>
                        </tr>
                    @empty
                        <x-ui.empty colspan="7" icon="users" title="No accounts"/>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-ui.pager :paginator="$meta"/>
    </div>

    <x-ui.modal wire:model="openId" :title="$detail['name'] ?? ''" size="xl">
        @if ($detail)
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <p class="text-sm text-slate-600">Tier {{ $detail['tier'] ?? '—' }} · call every {{ $detail['visit_frequency_days'] ?? '—' }} days · potential {{ $detail['potential_value'] !== null ? fmt()->number($detail['potential_value'], 0) : '—' }}</p>
                @if ($canManage && ! $profile)<button type="button" class="btn-light btn-sm" wire:click="editProfile(@js($detail))">Edit profile</button>@endif
            </div>
            @if ($profile)
                <form wire:submit="saveProfile" class="mb-4 grid gap-3 rounded-lg border border-slate-200 bg-slate-50 p-3 sm:grid-cols-4">
                    <x-ui.field label="Tier" for="profile.tier"><select wire:model="profile.tier" class="form-select py-2"><option value="">None</option><option>A</option><option>B</option><option>C</option></select></x-ui.field>
                    <x-ui.field label="Call every (days)" for="profile.visit_frequency_days"><input wire:model="profile.visit_frequency_days" type="number" min="1" class="form-input py-2"></x-ui.field>
                    <x-ui.field label="Potential value" for="profile.potential_value"><input wire:model="profile.potential_value" inputmode="decimal" class="form-input py-2"></x-ui.field>
                    <div class="flex items-end gap-2"><button type="button" class="btn-light btn-sm" wire:click="$set('profile', null)">Cancel</button><button class="btn-primary btn-sm">Save</button></div>
                </form>
            @endif

            <x-ui.tabs :current="$tab" :tabs="['timeline' => 'Timeline', 'contacts' => 'Contacts']"/>

            @if ($tab === 'timeline')
                <ol class="space-y-3">
                    @forelse ($timeline as $e)
                        <li class="flex gap-3 text-sm">
                            <span class="mt-0.5 shrink-0"><x-ui.badge tone="blue">{{ str_replace('_', ' ', $e['type']) }}</x-ui.badge></span>
                            <span class="min-w-0"><span class="font-medium text-navy-900">{{ $e['title'] }}</span>
                                <span class="muted block">{{ $e['at'] ? fmt()->dateTime($e['at']) : '' }}{{ $e['by_name'] ? ' · '.$e['by_name'] : '' }}</span></span>
                        </li>
                    @empty
                        <li class="text-sm text-slate-500">Nothing recorded with this customer yet.</li>
                    @endforelse
                </ol>
            @else
                <ul class="divide-y divide-slate-100 rounded-lg border border-slate-200 text-sm">
                    @forelse ($contacts as $c)
                        <li class="flex flex-wrap items-start justify-between gap-3 px-3 py-2.5" wire:key="cc-{{ $c['id'] }}">
                            <span>
                                <span class="font-medium text-navy-900">{{ $c['name'] }}</span>@if ($c['is_primary'])<x-ui.badge tone="green" class="ml-1">primary</x-ui.badge>@endif
                                <span class="muted block">{{ collect([$c['professional_type'], $c['specialty'], $c['job_title'], $c['email'], $c['phone']])->filter()->implode(' · ') }}</span>
                                <span class="mt-1 block text-xs">Consent: <x-ui.badge :status="$c['contact_consent'] === 'granted' ? 'active' : ($c['contact_consent'] === 'withdrawn' ? 'revoked' : 'pending')">{{ $c['contact_consent'] }}</x-ui.badge>
                                    @if ($c['consent_source'])<span class="text-slate-400">{{ $c['consent_source'] }}</span>@endif</span>
                            </span>
                            @unless ($c['anonymized'])
                                <span class="flex gap-3 text-xs">
                                    @if ($c['contact_consent'] !== 'granted')<button type="button" class="link" wire:click="consent('{{ $c['id'] }}', 'granted')">Record consent</button>@endif
                                    @if ($c['contact_consent'] !== 'withdrawn')<button type="button" class="link" wire:click="consent('{{ $c['id'] }}', 'withdrawn')">Withdraw consent</button>@endif
                                    @if ($canManage)<button type="button" class="link text-rose-700" wire:click="anonymize('{{ $c['id'] }}')" wire:confirm="Erase this contact's personal details? This cannot be undone.">Anonymize</button>@endif
                                </span>
                            @else
                                <x-ui.badge tone="slate">anonymized</x-ui.badge>
                            @endunless
                        </li>
                    @empty
                        <li class="px-3 py-3 text-slate-500">No contacts.</li>
                    @endforelse
                </ul>
                @if (! $contact)
                    <button type="button" class="link mt-3 text-sm" wire:click="newContact">+ Add a contact</button>
                @else
                    <form wire:submit="saveContact" class="mt-4 grid gap-3 rounded-lg border border-slate-200 bg-slate-50 p-3 sm:grid-cols-3">
                        <x-ui.field label="Name" for="contact.name"><input wire:model="contact.name" class="form-input py-2"></x-ui.field>
                        <x-ui.field label="Profession" for="contact.professional_type"><select wire:model="contact.professional_type" class="form-select py-2"><option value="">—</option>@foreach ($professionalTypes as $t)<option value="{{ $t }}">{{ ucfirst($t) }}</option>@endforeach</select></x-ui.field>
                        <x-ui.field label="Specialty" for="contact.specialty"><input wire:model="contact.specialty" class="form-input py-2"></x-ui.field>
                        <x-ui.field label="Email" for="contact.email"><input wire:model="contact.email" class="form-input py-2"></x-ui.field>
                        <x-ui.field label="Phone" for="contact.phone"><input wire:model="contact.phone" class="form-input py-2"></x-ui.field>
                        <x-ui.field label="Licence no." for="contact.professional_licence"><input wire:model="contact.professional_licence" class="form-input py-2"></x-ui.field>
                        <x-ui.field label="Consent" for="contact.contact_consent"><select wire:model.live="contact.contact_consent" class="form-select py-2"><option value="unknown">Not asked</option><option value="granted">Granted</option><option value="withdrawn">Refused</option></select></x-ui.field>
                        @if ($contact['contact_consent'] !== 'unknown')<x-ui.field label="How it was given" for="contact.consent_source"><input wire:model="contact.consent_source" class="form-input py-2" placeholder="Signed form, 12 May"></x-ui.field>@endif
                        <label class="flex items-center gap-2 self-end pb-2 text-sm"><input type="checkbox" wire:model="contact.is_primary" class="form-check">Primary</label>
                        <div class="flex justify-end gap-2 sm:col-span-3"><button type="button" class="btn-light btn-sm" wire:click="$set('contact', null)">Cancel</button><button class="btn-primary btn-sm">Add contact</button></div>
                    </form>
                @endif
            @endif
        @endif
    </x-ui.modal>
</div>

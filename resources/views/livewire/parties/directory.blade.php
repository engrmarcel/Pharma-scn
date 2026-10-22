<div>
    <x-ui.toolbar>
        <x-ui.search placeholder="Name or code…"/>
        <x-ui.field label="Status" for="status">
            <select id="status" wire:model.live="status" class="form-select w-36"><option value="">All</option><option value="active">Active</option><option value="on_hold">On hold</option><option value="inactive">Inactive</option></select>
        </x-ui.field>
        <x-ui.field label="Type" for="type">
            <select id="type" wire:model.live="type" class="form-select w-44"><option value="">All types</option>@foreach ($types as $t)<option value="{{ $t }}">{{ ucfirst(str_replace('_', ' ', $t)) }}</option>@endforeach</select>
        </x-ui.field>
        <x-slot:actions>
            @if ($canManage)<button type="button" class="btn-primary" wire:click="create"><x-heroicon-o-plus class="size-4"/>New {{ $singular }}</button>@endif
        </x-slot:actions>
    </x-ui.toolbar>

    <div class="card overflow-hidden">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Code</th><th>Name</th><th>Type</th><th>City</th><th>Terms</th><th class="num">Credit limit</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    @forelse ($rows as $r)
                        <tr wire:key="p-{{ $r['id'] }}" class="cursor-pointer" wire:click="open('{{ $r['id'] }}')">
                            <td class="font-mono text-xs font-semibold">{{ $r['code'] }}</td>
                            <td><span class="font-medium text-navy-900">{{ $r['name'] }}</span>@if ($r['email'] ?? null)<span class="muted block">{{ $r['email'] }}</span>@endif</td>
                            <td>{{ ucfirst(str_replace('_', ' ', $r['type'] ?? '')) }}</td>
                            <td>{{ $r['city'] ?? '—' }}</td>
                            <td>{{ $r['payment_terms_days'] }} days</td>
                            <td class="num">{{ $r['credit_limit'] !== null ? fmt()->money($r['credit_limit'], $r['currency_code']) : '—' }}</td>
                            <td><x-ui.badge :status="$r['status']"/></td>
                            <td class="text-right"><x-heroicon-m-chevron-right class="ml-auto size-4 text-slate-400"/></td>
                        </tr>
                    @empty
                        <x-ui.empty colspan="8" icon="user-group" title="No {{ $kind }} found"/>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-ui.pager :paginator="$meta"/>
    </div>

    {{-- Detail --}}
    <x-ui.modal wire:model="openId" :title="$detail['name'] ?? ''" size="xl">
        @if ($detail)
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div class="flex flex-wrap items-center gap-2 text-sm text-slate-500">
                    <span class="font-mono font-semibold text-navy-900">{{ $detail['code'] }}</span> · {{ ucfirst(str_replace('_', ' ', $detail['type'])) }} <x-ui.badge :status="$detail['status']"/>
                </div>
                @if ($canManage)<button type="button" class="btn-light btn-sm" wire:click="edit(@js($detail))"><x-heroicon-o-pencil-square class="size-4"/>Edit</button>@endif
            </div>
            <x-ui.tabs :current="$detailTab" model="detailTab" :tabs="array_filter([
                'overview' => 'Overview',
                'statement' => $kind === 'customers' ? 'Statement' : null,
                'prices' => $kind === 'suppliers' ? 'Price list' : null,
                'performance' => $kind === 'suppliers' ? 'Performance' : null,
            ])"/>

            @if ($detailTab === 'overview')
                <div class="grid gap-6 lg:grid-cols-2">
                    <x-ui.dl :items="array_merge([
                        'Email' => $detail['email'] ?? null, 'Phone' => $detail['phone'] ?? null, 'Tax ID' => $detail['tax_identification_number'] ?? null,
                        'Address' => collect([$detail['address_line1'] ?? null, $detail['city'] ?? null, $detail['state_region'] ?? null, $detail['country_code'] ?? null])->filter()->implode(', '),
                        'Currency' => $detail['currency_code'] ?? null, 'Payment terms' => ($detail['payment_terms_days'] ?? 0).' days',
                        'Credit limit' => ($detail['credit_limit'] ?? null) !== null ? fmt()->money($detail['credit_limit'], $detail['currency_code']) : null,
                    ], collect($extraFields)->mapWithKeys(fn ($label, $key) => [$label => $detail[$key] ?? null])->all(), ['Notes' => $detail['notes'] ?? null])"/>
                    <div>
                        <h3 class="mb-2 text-sm font-semibold text-navy-950">Contacts</h3>
                        <ul class="divide-y divide-slate-100 rounded-lg border border-slate-200">
                            @forelse ($detail['contacts'] as $c)
                                <li class="flex items-start justify-between gap-3 px-3 py-2 text-sm" wire:key="c-{{ $c['id'] }}">
                                    <span>
                                        <span class="font-medium text-navy-900">{{ $c['name'] }}</span>@if ($c['is_primary'])<x-ui.badge tone="green" class="ml-1">primary</x-ui.badge>@endif
                                        <span class="muted block">{{ collect([$c['job_title'], $c['email'], $c['phone']])->filter()->implode(' · ') }}</span>
                                    </span>
                                    @if ($canManage)<button type="button" class="text-slate-400 hover:text-rose-600" wire:click="removeContact('{{ $c['id'] }}')" wire:confirm="Remove this contact?" aria-label="Remove"><x-heroicon-o-trash class="size-4"/></button>@endif
                                </li>
                            @empty
                                <li class="px-3 py-3 text-sm text-slate-500">No contacts yet.</li>
                            @endforelse
                        </ul>
                        @if ($canManage)
                            <form wire:submit="addContact" class="mt-3 grid gap-2 sm:grid-cols-2">
                                <x-ui.field for="contact.name"><input wire:model="contact.name" class="form-input py-2" placeholder="Name"></x-ui.field>
                                <input wire:model="contact.job_title" class="form-input py-2" placeholder="Job title">
                                <x-ui.field for="contact.email"><input wire:model="contact.email" class="form-input py-2" placeholder="Email"></x-ui.field>
                                <input wire:model="contact.phone" class="form-input py-2" placeholder="Phone">
                                <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="contact.is_primary" class="form-check">Primary contact</label>
                                <button class="btn-primary btn-sm justify-self-end">Add contact</button>
                            </form>
                        @endif
                    </div>
                </div>
            @elseif ($detailTab === 'statement')
                @php $st = $detail['statement'] ?? null; @endphp
                @if (! $st)
                    <p class="text-sm text-slate-500">The statement needs the sales module and permission to view sales.</p>
                @else
                    <p class="mb-3 text-sm text-slate-600">{{ fmt()->date($st['from']) }} to {{ fmt()->date($st['to']) }} · opening balance <strong>{{ fmt()->money($st['opening_balance'], $detail['currency_code']) }}</strong></p>
                    <div class="table-wrap rounded-lg border border-slate-200">
                        <table class="data-table">
                            <thead><tr><th>Date</th><th>Entry</th><th>Description</th><th class="num">Debit</th><th class="num">Credit</th><th class="num">Balance</th></tr></thead>
                            <tbody>
                                @forelse ($st['lines'] as $l)
                                    <tr><td>{{ fmt()->date($l['entry_date']) }}</td><td class="font-mono text-xs">{{ $l['number'] }}</td><td>{{ $l['description'] }}</td>
                                        <td class="num">{{ (float) $l['debit'] ? fmt()->number($l['debit'], 2) : '' }}</td><td class="num">{{ (float) $l['credit'] ? fmt()->number($l['credit'], 2) : '' }}</td><td class="num font-medium">{{ fmt()->number($l['balance'], 2) }}</td></tr>
                                @empty
                                    <x-ui.empty colspan="6" icon="document-text" title="No transactions in this period"/>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <p class="mt-3 text-right text-sm">Closing balance <strong>{{ fmt()->money($st['closing_balance'], $detail['currency_code']) }}</strong></p>
                @endif
            @elseif ($detailTab === 'performance')
                @php $pf = $detail['performance'] ?? null; @endphp
                @if (! $pf)
                    <p class="text-sm text-slate-500">Performance needs the procurement module and permission to view procurement.</p>
                @else
                    <div class="grid gap-4 sm:grid-cols-3">
                        <x-ui.stat label="Orders placed" :value="$pf['orders_placed']"/>
                        <x-ui.stat label="On time" :value="$pf['on_time_rate'] !== null ? round($pf['on_time_rate'] * 100).'%' : '—'" tone="green"/>
                        <x-ui.stat label="Fill rate" :value="$pf['fill_rate'] !== null ? round($pf['fill_rate'] * 100).'%' : '—'" tone="gold"/>
                        <x-ui.stat label="Average lead time" :value="$pf['average_lead_time_days'] !== null ? $pf['average_lead_time_days'].' days' : '—'"/>
                        <x-ui.stat label="Invoices with variance" :value="$pf['invoices_with_variance']" tone="amber"/>
                        <x-ui.stat label="Returns" :value="$pf['returns']" tone="red"/>
                    </div>
                    <p class="mt-3 text-xs text-slate-500">Computed from your own recorded orders and receipts only.</p>
                @endif
            @elseif ($detailTab === 'prices')
                @if (($detail['prices'] ?? null) === null)
                    <p class="text-sm text-slate-500">The price list needs the procurement module and permission to view procurement.</p>
                @elseif ($priceLines === null)
                    <div class="mb-3 flex justify-end">@if ($canPriceList)<button type="button" class="btn-light btn-sm" wire:click="editPriceList(@js($detail['prices']), @js($detail['productNames']))"><x-heroicon-o-pencil-square class="size-4"/>Edit price list</button>@endif</div>
                    <div class="table-wrap rounded-lg border border-slate-200">
                        <table class="data-table">
                            <thead><tr><th>Product</th><th>Pack</th><th class="num">Unit price</th><th class="num">Min. qty</th><th>Valid</th></tr></thead>
                            <tbody>
                                @forelse ($detail['prices'] as $p)
                                    <tr><td>{{ $detail['productNames'][$p['product_id']] ?? $p['product_id'] }}</td><td>{{ $p['package_level_code'] }}</td>
                                        <td class="num">{{ fmt()->money($p['unit_price'], $p['currency_code']) }}</td><td class="num">{{ fmt()->number($p['minimum_quantity'], 2) }}</td>
                                        <td class="text-xs">{{ $p['valid_from'] ? fmt()->date($p['valid_from']) : 'any' }} – {{ $p['valid_to'] ? fmt()->date($p['valid_to']) : 'open' }}</td></tr>
                                @empty
                                    <x-ui.empty colspan="5" icon="currency-dollar" title="No agreed prices yet"/>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                @else
                    <form wire:submit="savePriceList" class="space-y-2">
                        @error('price.items') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
                        @foreach ($priceLines as $i => $l)
                            <div class="grid items-start gap-2 rounded-lg border border-slate-200 p-2 md:grid-cols-[1fr_9rem_8rem_7rem_9rem_9rem_auto]" wire:key="pl-{{ $i }}">
                                <x-ui.field :for="'price.items.'.$i.'.product_id'">@include('livewire.partials.product-picker', ['target' => 'price.'.$i, 'selected' => $l['name']])</x-ui.field>
                                <x-ui.field :for="'price.items.'.$i.'.package_level_code'"><select wire:model="priceLines.{{ $i }}.package_level_code" class="form-select py-2">@foreach ($l['levels'] as $code => $name)<option value="{{ $code }}">{{ $name }}</option>@endforeach</select></x-ui.field>
                                <x-ui.field :for="'price.items.'.$i.'.unit_price'"><input wire:model="priceLines.{{ $i }}.unit_price" class="form-input py-2" placeholder="Price" inputmode="decimal"></x-ui.field>
                                <x-ui.field :for="'price.items.'.$i.'.minimum_quantity'"><input wire:model="priceLines.{{ $i }}.minimum_quantity" class="form-input py-2" placeholder="Min" inputmode="decimal"></x-ui.field>
                                <input type="date" wire:model="priceLines.{{ $i }}.valid_from" class="form-input py-2" aria-label="Valid from">
                                <input type="date" wire:model="priceLines.{{ $i }}.valid_to" class="form-input py-2" aria-label="Valid to">
                                <button type="button" class="p-2 text-slate-400 hover:text-rose-600" wire:click="removePriceLine({{ $i }})" aria-label="Remove"><x-heroicon-o-trash class="size-4"/></button>
                            </div>
                        @endforeach
                        <div class="flex justify-between pt-2">
                            <button type="button" class="link text-sm" wire:click="addPriceLine">+ Add product</button>
                            <span class="flex gap-2"><button type="button" class="btn-light btn-sm" wire:click="$set('priceLines', null)">Cancel</button><button class="btn-primary btn-sm">Save price list</button></span>
                        </div>
                    </form>
                @endif
            @endif
        @endif
    </x-ui.modal>

    {{-- Create / edit --}}
    <x-ui.modal wire:model="party" :title="($party['id'] ?? null) ? 'Edit '.$singular : 'New '.$singular" size="xl">
        @if ($party)
            <form wire:submit="save" id="party-form" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <x-ui.field label="Code" for="party.code"><input id="party.code" wire:model="party.code" class="form-input font-mono uppercase"></x-ui.field>
                <x-ui.field label="Name" for="party.name" class="lg:col-span-2"><input id="party.name" wire:model="party.name" class="form-input"></x-ui.field>
                <x-ui.field label="Type" for="party.type">
                    <select id="party.type" wire:model="party.type" class="form-select">@foreach ($types as $t)<option value="{{ $t }}">{{ ucfirst(str_replace('_', ' ', $t)) }}</option>@endforeach</select>
                </x-ui.field>
                <x-ui.field label="Email" for="party.email"><input id="party.email" type="email" wire:model="party.email" class="form-input"></x-ui.field>
                <x-ui.field label="Phone" for="party.phone"><input id="party.phone" wire:model="party.phone" class="form-input"></x-ui.field>
                <x-ui.field label="Tax ID" for="party.tax_identification_number"><input id="party.tax_identification_number" wire:model="party.tax_identification_number" class="form-input"></x-ui.field>
                <x-ui.field label="Address" for="party.address_line1" class="lg:col-span-2"><input id="party.address_line1" wire:model="party.address_line1" class="form-input"></x-ui.field>
                <x-ui.field label="City" for="party.city"><input id="party.city" wire:model="party.city" class="form-input"></x-ui.field>
                <x-ui.field label="State / region" for="party.state_region"><input id="party.state_region" wire:model="party.state_region" class="form-input"></x-ui.field>
                <x-ui.field label="Country" for="party.country_code"><input id="party.country_code" wire:model="party.country_code" maxlength="2" class="form-input uppercase"></x-ui.field>
                <x-ui.field label="Payment terms (days)" for="party.payment_terms_days"><input id="party.payment_terms_days" type="number" min="0" wire:model="party.payment_terms_days" class="form-input"></x-ui.field>
                <x-ui.field label="Credit limit" for="party.credit_limit"><input id="party.credit_limit" wire:model="party.credit_limit" inputmode="decimal" class="form-input"></x-ui.field>
                <x-ui.field label="Status" for="party.status">
                    <select id="party.status" wire:model="party.status" class="form-select"><option value="active">Active</option><option value="on_hold">On hold</option><option value="inactive">Inactive</option></select>
                </x-ui.field>
                @foreach ($extraFields as $key => $label)
                    <x-ui.field :label="$label" :for="'party.'.$key"><input id="party.{{ $key }}" wire:model="party.{{ $key }}" class="form-input"></x-ui.field>
                @endforeach
                <x-ui.field label="Notes" for="party.notes" class="sm:col-span-2 lg:col-span-3"><textarea id="party.notes" wire:model="party.notes" rows="2" class="form-input"></textarea></x-ui.field>
            </form>
        @endif
        <x-slot:footer>
            <button type="button" class="btn-light" @click="open = null">Cancel</button>
            <button type="submit" form="party-form" class="btn-primary" wire:loading.attr="disabled">Save</button>
        </x-slot:footer>
    </x-ui.modal>
</div>

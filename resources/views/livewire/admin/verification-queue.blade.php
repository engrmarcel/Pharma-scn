<div>
    <select wire:model.live="status" class="mb-4 form-field px-3 py-2 text-sm">
        @foreach (\App\Domain\Organizations\Enums\VerificationStatus::cases() as $s)
            <option value="{{ $s->value }}">{{ ucfirst(str_replace('_', ' ', $s->value)) }}</option>
        @endforeach
    </select>

    <div class="grid gap-6 lg:grid-cols-5">
        <div class="lg:col-span-2">
            @forelse ($organizations as $org)
                <button wire:key="{{ $org->id }}" wire:click="review('{{ $org->id }}')"
                        class="mb-2 block w-full rounded-lg border bg-white p-3 text-left text-sm shadow-sm hover:bg-slate-50 {{ $reviewing === $org->id ? 'border-[var(--brand-primary)] ring-1 ring-[var(--brand-primary)]' : 'border-slate-200' }}">
                    <div class="font-medium">{{ $org->name }}</div>
                    <div class="text-xs text-slate-500">{{ $org->type->label() }} · {{ $org->country_code }} · updated {{ $org->updated_at->diffForHumans() }}</div>
                </button>
            @empty
                <p class="text-sm text-slate-500">No organizations with this status.</p>
            @endforelse
            {{ $organizations->links() }}
        </div>

        <div class="lg:col-span-3">
            @if ($current)
                <div class="card space-y-5 p-5 text-sm">
                    <div>
                        <h2 class="text-lg font-semibold">{{ $current->name }}</h2>
                        <p class="text-slate-500">{{ $current->type->label() }} · {{ $current->country_code }} · <x-ui.badge :status="$current->verification_status->value">{{ str_replace('_', ' ', $current->verification_status->value) }}</x-ui.badge></p>
                    </div>

                    <dl class="grid grid-cols-3 gap-x-3 gap-y-1 text-slate-700">
                        @foreach ([
                            'Legal name' => $current->legal_name,
                            'Registration no.' => $current->business_registration_number,
                            'Tax ID' => $current->tax_identification_number,
                            'Email' => $current->email,
                            'Phone' => $current->phone,
                            'Address' => collect([$current->address_line1, $current->address_line2, $current->city, $current->state_region, $current->postal_code])->filter()->implode(', '),
                        ] as $label => $value)
                            <dt class="text-slate-500">{{ $label }}</dt><dd class="col-span-2">{{ $value ?: '—' }}</dd>
                        @endforeach
                    </dl>

                    @if ($requirements)
                        <div>
                            <h3 class="font-medium">Country checklist</h3>
                            <ul class="mt-1 space-y-1">
                                @foreach ($requirements['documents'] as $r)
                                    <li class="flex items-center gap-2">@if ($r['present'])<x-heroicon-s-check-circle class="size-4 text-emerald-500"/>@else<x-heroicon-o-x-circle class="size-4 text-rose-500"/>@endif Document: {{ str_replace('_', ' ', $r['category']) }}</li>
                                @endforeach
                                @foreach ($requirements['identifiers'] as $r)
                                    <li class="flex items-center gap-2">@if ($r['present'])<x-heroicon-s-check-circle class="size-4 text-emerald-500"/>@else<x-heroicon-o-x-circle class="size-4 text-rose-500"/>@endif {{ $r['name'] }}</li>
                                @endforeach
                                @foreach ($requirements['profile_fields'] as $r)
                                    <li class="flex items-center gap-2">@if ($r['present'])<x-heroicon-s-check-circle class="size-4 text-emerald-500"/>@else<x-heroicon-o-x-circle class="size-4 text-rose-500"/>@endif {{ ucfirst($r['label']) }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <div>
                        <h3 class="font-medium">Documents</h3>
                        <ul class="mt-1 divide-y divide-slate-100">
                            @forelse ($current->documents as $doc)
                                <li class="flex items-center justify-between gap-3 py-1.5" wire:key="vdoc-{{ $doc->id }}">
                                    <span class="min-w-0">
                                        <span class="block truncate font-medium">{{ $doc->original_name }}</span>
                                        <span class="text-xs text-slate-500">{{ ucfirst(str_replace('_', ' ', $doc->category)) }} · {{ number_format($doc->size_bytes / 1024) }} KB · scan: {{ $doc->scan_status }} · {{ $doc->created_at->toDayDateTimeString() }}</span>
                                    </span>
                                    @if ($canDecide)
                                        <button type="button" class="btn-light btn-sm" wire:click="downloadDocument('{{ $doc->id }}')"><x-heroicon-o-arrow-down-tray class="size-4"/>Open</button>
                                    @endif
                                </li>
                            @empty
                                <li class="py-1.5 text-slate-500">No documents uploaded.</li>
                            @endforelse
                        </ul>
                    </div>

                    <div>
                        <h3 class="font-medium">Licences and registrations</h3>
                        @forelse ($identifiers as $i)
                            <div class="mt-1">{{ $i->authority?->code }} · {{ str_replace('_', ' ', $i->identifier_type) }}: <span class="font-mono text-xs">{{ $i->value }}</span>
                                <span class="text-xs text-slate-500">(declared {{ $i->declared_status }}{{ $i->expiry_date ? ', expires '.$i->expiry_date->toDateString() : '' }})</span></div>
                        @empty
                            <p class="mt-1 text-slate-500">None recorded.</p>
                        @endforelse
                    </div>

                    <div>
                        <h3 class="font-medium">History</h3>
                        @foreach ($current->verifications as $v)
                            <div class="text-xs text-slate-600">{{ $v->created_at->toDayDateTimeString() }} — {{ str_replace('_', ' ', $v->action) }} by {{ $v->actor?->name ?? 'system' }}@if ($v->notes): “{{ $v->notes }}”@endif</div>
                        @endforeach
                    </div>

                    @if ($canDecide && $current->verification_status->canBeDecided())
                        <div>
                            <textarea wire:model="notes" rows="3" placeholder="Notes to the organization (required to reject or request changes)"
                                      class="w-full form-field px-3 py-2"></textarea>
                            @error('notes') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                            <div class="mt-2 flex gap-2">
                                <button type="button" wire:click="decide('approve')" wire:loading.attr="disabled" wire:confirm="Verify {{ $current->name }}?" class="btn-primary px-3 py-1.5 font-medium text-white">Approve</button>
                                <button type="button" wire:click="decide('request_changes')" wire:loading.attr="disabled" class="form-field px-3 py-1.5">Request changes</button>
                                <button type="button" wire:click="decide('reject')" wire:loading.attr="disabled" class="rounded border border-red-300 px-3 py-1.5 text-red-700">Reject</button>
                            </div>
                        </div>
                    @endif
                </div>
            @else
                <p class="card p-5 text-sm text-slate-500">Choose an organization to review its details and documents.</p>
            @endif
        </div>
    </div>
</div>

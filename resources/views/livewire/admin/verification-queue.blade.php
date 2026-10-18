<div>
    <h1 class="mb-4 text-2xl font-semibold">Organization verification</h1>

    <select wire:model.live="status" class="mb-4 rounded border border-slate-300 px-3 py-2 text-sm">
        @foreach (\App\Domain\Organizations\Enums\VerificationStatus::cases() as $s)
            <option value="{{ $s->value }}">{{ str_replace('_', ' ', $s->value) }}</option>
        @endforeach
    </select>

    <div class="grid gap-6 lg:grid-cols-2">
        <div>
            @forelse ($organizations as $org)
                <button wire:key="{{ $org->id }}" wire:click="review('{{ $org->id }}')"
                        class="mb-2 block w-full rounded-lg border bg-white p-3 text-left text-sm shadow-sm hover:bg-slate-50 {{ $reviewing === $org->id ? 'border-[var(--brand-primary)]' : 'border-slate-200' }}">
                    <div class="font-medium">{{ $org->name }}</div>
                    <div class="text-xs text-slate-500">{{ $org->type->label() }} · {{ $org->country_code }} · updated {{ $org->updated_at->diffForHumans() }}</div>
                </button>
            @empty
                <p class="text-sm text-slate-500">No organizations with this status.</p>
            @endforelse
            {{ $organizations->links() }}
        </div>

        @if ($current)
            <div class="rounded-lg border border-slate-200 bg-white p-4 text-sm shadow-sm">
                <h2 class="text-lg font-semibold">{{ $current->name }}</h2>
                <dl class="mt-2 grid grid-cols-3 gap-1 text-slate-700">
                    <dt class="text-slate-500">Legal name</dt><dd class="col-span-2">{{ $current->legal_name ?? '—' }}</dd>
                    <dt class="text-slate-500">Registration no.</dt><dd class="col-span-2">{{ $current->business_registration_number ?? '—' }}</dd>
                    <dt class="text-slate-500">Status</dt><dd class="col-span-2">{{ str_replace('_', ' ', $current->verification_status->value) }}</dd>
                </dl>

                <h3 class="mt-4 font-medium">Documents</h3>
                @forelse ($current->documents as $doc)
                    <div>{{ str_replace('_', ' ', $doc->category) }}: {{ $doc->original_name }} <span class="text-xs text-slate-500">(scan: {{ $doc->scan_status }})</span></div>
                @empty
                    <p class="text-slate-500">No documents uploaded.</p>
                @endforelse

                <h3 class="mt-4 font-medium">History</h3>
                @foreach ($current->verifications as $v)
                    <div class="text-xs text-slate-600">{{ $v->created_at->toDayDateTimeString() }} — {{ $v->action }} by {{ $v->actor?->name ?? 'system' }}@if ($v->notes): “{{ $v->notes }}”@endif</div>
                @endforeach

                @if ($canDecide && $current->verification_status->canBeDecided())
                    <textarea wire:model="notes" rows="3" placeholder="Notes (required to reject or request changes)"
                              class="mt-4 w-full rounded border border-slate-300 px-3 py-2"></textarea>
                    @error('notes') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                    <div class="mt-2 flex gap-2">
                        <button wire:click="decide('approve')" class="rounded bg-[var(--brand-primary)] px-3 py-1.5 font-medium text-white">Approve</button>
                        <button wire:click="decide('request_changes')" class="rounded border border-slate-300 px-3 py-1.5">Request changes</button>
                        <button wire:click="decide('reject')" class="rounded border border-red-300 px-3 py-1.5 text-red-700">Reject</button>
                    </div>
                @endif
            </div>
        @endif
    </div>
</div>

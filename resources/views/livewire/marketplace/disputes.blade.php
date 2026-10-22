<div>
    <div class="card overflow-hidden">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Dispute</th><th>With</th><th>Problem</th><th>Asked for</th><th>Raised</th><th>Status</th></tr></thead>
                <tbody>
                    @forelse ($rows as $d)
                        @php $buyer = $d['buyer_organization_id'] === $me; @endphp
                        <tr wire:key="d-{{ $d['id'] }}" class="cursor-pointer" wire:click="$set('openId', '{{ $d['id'] }}')">
                            <td class="font-mono text-xs font-semibold">{{ $d['number'] }}</td>
                            <td class="font-medium text-navy-900">{{ $orgs[$buyer ? $d['supplier_organization_id'] : $d['buyer_organization_id']] ?? '' }}</td>
                            <td>{{ str_replace('_', ' ', $d['type']) }}</td>
                            <td>{{ str_replace('_', ' ', $d['requested_resolution']) }}</td>
                            <td>{{ fmt()->date($d['created_at']) }}</td>
                            <td><x-ui.badge :status="$d['status']"/></td>
                        </tr>
                    @empty
                        <x-ui.empty colspan="6" icon="scale" title="No disputes">Raise a dispute from the order it concerns.</x-ui.empty>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <x-ui.modal wire:model="openId" :title="'Dispute '.($detail['number'] ?? '')" size="lg">
        @if ($detail)
            @php $buyer = $detail['buyer_organization_id'] === $me; $open = ! in_array($detail['status'], ['resolved', 'closed'], true); @endphp
            <p class="text-sm"><x-ui.badge :status="$detail['status']"/> {{ str_replace('_', ' ', $detail['type']) }} · asks for {{ str_replace('_', ' ', $detail['requested_resolution']) }}</p>
            <p class="mt-2 whitespace-pre-line text-sm text-slate-700">{{ $detail['description'] }}</p>
            @if ($detail['resolution'])<p class="mt-2 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-900">Resolved: {{ str_replace('_', ' ', $detail['resolution']) }}. {{ $detail['resolution_notes'] }}</p>@endif
            <div class="mt-4 max-h-72 space-y-2 overflow-y-auto">
                @foreach ($detail['messages'] as $m)
                    @php $mine = ($m['author_side'] === 'buyer') === $buyer; @endphp
                    <div class="flex {{ $mine ? 'justify-end' : '' }}"><div class="max-w-[80%] rounded-xl px-3 py-2 text-sm {{ $m['author_side'] === 'platform' ? 'bg-amber-50 text-amber-900' : ($mine ? 'bg-navy-50 text-navy-950' : 'bg-slate-100 text-slate-800') }}">
                        <p>{{ $m['body'] }}</p><p class="mt-1 text-[11px] opacity-60">{{ $m['author_side'] }} · {{ fmt()->dateTime($m['created_at']) }}</p></div></div>
                @endforeach
            </div>
            @if ($open)
                <form wire:submit="send" class="mt-4 flex gap-2">
                    <x-ui.field for="message.body" class="flex-1"><input wire:model="message" class="form-input py-2" placeholder="Write a message…"></x-ui.field>
                    <button class="btn-primary">Send</button>
                </form>
            @endif
            @if ($resolving)
                <form wire:submit="resolve" class="mt-4 space-y-3 rounded-lg border border-slate-200 bg-slate-50 p-4">
                    <x-ui.field label="Resolution" for="resolving.resolution"><select wire:model="resolving.resolution" class="form-select py-2"><option value="replacement">Replacement</option><option value="credit_note">Credit note (from the order screen)</option><option value="rejected">Rejected</option><option value="other">Other</option></select></x-ui.field>
                    <x-ui.field label="Notes" for="resolving.notes"><textarea wire:model="resolving.notes" rows="2" class="form-input"></textarea></x-ui.field>
                    @error('resolving.credit_lines') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
                    <div class="flex justify-end gap-2"><button type="button" class="btn-light btn-sm" wire:click="$set('resolving', null)">Back</button><button class="btn-primary btn-sm">Resolve</button></div>
                </form>
            @endif
        @endif
        <x-slot:footer>
            @if ($detail && ! in_array($detail['status'], ['resolved', 'closed', 'escalated'], true) && ! $resolving)
                <button type="button" class="btn-light" wire:click="escalate" wire:confirm="Ask platform staff to step in?">Escalate</button>
                @if ($detail['supplier_organization_id'] === $me)<button type="button" class="btn-primary" wire:click="startResolve">Resolve</button>@endif
            @endif
        </x-slot:footer>
    </x-ui.modal>
</div>

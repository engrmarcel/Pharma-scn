<div>
    <x-ui.tabs :current="$as" model="as" :tabs="['' => 'All', 'buyer' => 'Our suppliers', 'supplier' => 'Our buyers']"/>
    <x-ui.toolbar>
        <x-ui.field label="Status" for="status"><select id="status" wire:model.live="status" class="form-select w-40"><option value="">All</option>@foreach (['requested', 'approved', 'rejected', 'suspended'] as $s)<option value="{{ $s }}">{{ ucfirst($s) }}</option>@endforeach</select></x-ui.field>
        <x-slot:actions><a href="{{ route('marketplace.suppliers') }}" class="btn-light">Find suppliers</a></x-slot:actions>
    </x-ui.toolbar>

    <div class="card overflow-hidden">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Partner</th><th>They are</th><th>Requested</th><th>Terms</th><th>Message</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    @forelse ($rows as $r)
                        @php $partner = $r['your_side'] === 'buyer' ? $r['supplier'] : $r['buyer']; @endphp
                        <tr wire:key="rel-{{ $r['id'] }}">
                            <td><span class="font-medium text-navy-900">{{ $partner['name'] }}</span><span class="muted block">platform verification: {{ str_replace('_', ' ', $partner['verification']['status']) }}</span></td>
                            <td>{{ $r['your_side'] === 'buyer' ? 'our supplier' : 'our buyer' }}</td>
                            <td>{{ fmt()->date($r['created_at']) }}</td>
                            <td>{{ $r['status'] === 'approved' ? $r['payment_terms_days'].' days' : '—' }}</td>
                            <td class="max-w-xs text-xs text-slate-600">{{ $r['request_message'] }}@if ($r['decision_notes'])<span class="block text-slate-500">{{ $r['decision_notes'] }}</span>@endif</td>
                            <td><x-ui.badge :status="$r['status'] === 'requested' ? 'pending' : $r['status']">{{ $r['status'] }}</x-ui.badge></td>
                            <td class="whitespace-nowrap text-right">@if ($canManage)
                                @if ($r['status'] === 'requested' && $r['your_side'] === 'supplier')
                                    <button type="button" class="link text-xs" wire:click="start('{{ $r['id'] }}', 'approve', @js($partner['name']))">Approve</button>
                                    <button type="button" class="link ml-2 text-xs text-rose-700" wire:click="start('{{ $r['id'] }}', 'reject', @js($partner['name']))">Reject</button>
                                @elseif ($r['status'] === 'approved')
                                    <button type="button" class="link text-xs text-rose-700" wire:click="start('{{ $r['id'] }}', 'suspend', @js($partner['name']))">Suspend</button>
                                @endif
                            @endif</td>
                        </tr>
                    @empty
                        <x-ui.empty colspan="7" icon="link" title="No trading partners yet"/>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-ui.pager :paginator="$meta"/>
    </div>

    <x-ui.modal wire:model="deciding" :title="ucfirst($deciding['action'] ?? '').' '.($deciding['name'] ?? '')" size="sm">
        @if ($deciding)
            <form wire:submit="apply" id="dec-form" class="space-y-4">
                @if ($deciding['action'] === 'approve')<x-ui.field label="Payment terms (days)" for="deciding.payment_terms_days"><input id="deciding.payment_terms_days" type="number" min="0" wire:model="deciding.payment_terms_days" class="form-input"></x-ui.field>@endif
                <x-ui.field :label="$deciding['action'] === 'approve' ? 'Note (optional)' : 'Reason'" :for="$deciding['action'] === 'suspend' ? 'deciding.reason' : 'deciding.notes'"><textarea wire:model="deciding.notes" rows="2" class="form-input"></textarea></x-ui.field>
            </form>
        @endif
        <x-slot:footer><button type="button" class="btn-light" @click="open = null">Cancel</button><button type="submit" form="dec-form" class="btn-primary">Confirm</button></x-slot:footer>
    </x-ui.modal>
</div>

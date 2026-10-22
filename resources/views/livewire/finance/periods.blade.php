<div>
    <p class="mb-4 text-sm text-slate-600">Periods are created as postings arrive. A closed period refuses new postings, including reversals dated inside it.</p>
    <div class="card overflow-hidden">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Period</th><th>From</th><th>To</th><th>Status</th><th>Closed</th><th></th></tr></thead>
                <tbody>
                    @forelse ($periods as $p)
                        <tr wire:key="pd-{{ $p['id'] }}">
                            <td class="font-medium text-navy-900">{{ $p['name'] }}</td><td>{{ fmt()->date($p['start_date']) }}</td><td>{{ fmt()->date($p['end_date']) }}</td>
                            <td><x-ui.badge :status="$p['status'] === 'open' ? 'active' : 'locked'">{{ $p['status'] }}</x-ui.badge></td>
                            <td>{{ $p['closed_at'] ? fmt()->dateTime($p['closed_at']) : '—' }}</td>
                            <td class="text-right">@if ($canManage)
                                <button type="button" class="link text-xs" wire:click="start('{{ $p['id'] }}', '{{ $p['status'] === 'open' ? 'close' : 'reopen' }}', @js($p['name']))">{{ $p['status'] === 'open' ? 'Close' : 'Reopen' }}</button>
                            @endif</td>
                        </tr>
                    @empty
                        <x-ui.empty colspan="6" icon="calendar" title="No periods yet"/>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <x-ui.modal wire:model="changing" :title="ucfirst($changing['action'] ?? '').' '.($changing['name'] ?? '')" size="sm">
        @if ($changing)
            <form wire:submit="apply" id="period-form">
                <x-ui.field :label="$changing['action'] === 'reopen' ? 'Reason (required)' : 'Note (optional)'" for="changing.reason"><textarea id="changing.reason" wire:model="changing.reason" rows="2" class="form-input"></textarea></x-ui.field>
            </form>
        @endif
        <x-slot:footer>
            <button type="button" class="btn-light" @click="open = null">Cancel</button>
            <button type="submit" form="period-form" class="btn-primary">{{ ucfirst($changing['action'] ?? 'Apply') }}</button>
        </x-slot:footer>
    </x-ui.modal>
</div>

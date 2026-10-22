<div>
    <x-ui.toolbar>
        <x-ui.field label="Status" for="status">
            <select id="status" wire:model.live="status" class="form-select w-44"><option value="">All</option><option value="pending">To acknowledge</option><option value="acknowledged">Acknowledged</option><option value="completed">Completed</option></select>
        </x-ui.field>
    </x-ui.toolbar>

    <div class="card overflow-hidden">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Recall</th><th>Product</th><th>Batches</th><th>Class</th><th>Notified</th><th class="num">Held at notice</th><th>Status</th></tr></thead>
                <tbody>
                    @forelse ($rows as $n)
                        <tr wire:key="n-{{ $n['id'] }}" class="cursor-pointer" wire:click="$set('openId', '{{ $n['id'] }}')">
                            <td><span class="font-mono text-xs font-semibold">{{ $n['recall']['number'] }}</span><span class="block font-medium text-navy-900">{{ $n['recall']['title'] }}</span></td>
                            <td>{{ $names[$n['recall']['product_id']] ?? '' }}</td>
                            <td class="font-mono text-xs">{{ implode(', ', $n['recall']['batch_numbers']) }}</td>
                            <td>{{ str_replace('_', ' ', $n['recall']['classification']) }}</td>
                            <td>{{ fmt()->dateTime($n['notified_at']) }}</td>
                            <td class="num">{{ fmt()->number($n['held_at_notice'], 2) }}</td>
                            <td><x-ui.badge :status="$n['status'] === 'pending' ? 'overdue' : ($n['status'] === 'completed' ? 'done' : 'acknowledged')">{{ $n['status'] }}</x-ui.badge></td>
                        </tr>
                    @empty
                        <x-ui.empty colspan="7" icon="bell-alert" title="No recall notices">When a recall covers batches you hold, a notice appears here and the stock is quarantined.</x-ui.empty>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <x-ui.modal wire:model="openId" :title="($detail['recall']['number'] ?? '').' · '.($detail['recall']['title'] ?? '')" size="xl">
        @if ($detail)
            <div class="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm text-rose-900">
                <p class="font-semibold">Instructions</p><p class="mt-1 whitespace-pre-line">{{ $detail['recall']['quarantine_instructions'] }}</p>
            </div>
            <x-ui.dl class="mt-4" :cols="3" :items="['Product' => $names[$detail['recall']['product_id']] ?? null, 'Batches' => implode(', ', $detail['recall']['batch_numbers']),
                'Reason' => $detail['recall']['reason'], 'Held when notified' => fmt()->number($detail['held_at_notice'], 2), 'Quarantined then' => fmt()->number($detail['quarantined_at_notice'], 2),
                'Acknowledged' => $detail['acknowledged_at'] ? fmt()->dateTime($detail['acknowledged_at']) : null]"/>

            <h3 class="mb-2 mt-5 text-sm font-semibold text-navy-950">Recovery</h3>
            <div class="grid gap-3 sm:grid-cols-4">
                @foreach (['still_held' => 'Still held', 'written_off' => 'Written off', 'returned_to_supplier' => 'Returned', 'recovered_from_customers' => 'Recovered from customers', 'left_after_recall' => 'Left after recall', 'unaccounted' => 'Unaccounted'] as $k => $label)
                    <x-ui.stat :label="$label" :value="fmt()->number($detail['recovery'][$k] ?? 0, 2)" :tone="$k === 'unaccounted' && (float) ($detail['recovery'][$k] ?? 0) > 0 ? 'red' : 'navy'"/>
                @endforeach
            </div>

            <h3 class="mb-2 mt-5 text-sm font-semibold text-navy-950">Your customers who received these batches</h3>
            <ul class="divide-y divide-slate-100 rounded-lg border border-slate-200 text-sm">
                @forelse ($detail['your_customers'] as $c)
                    <li class="flex flex-wrap justify-between gap-2 px-3 py-2"><span>{{ $c['customer'] ?? 'Platform organization' }} @unless ($c['on_platform'])<x-ui.badge tone="amber">contact directly</x-ui.badge>@endunless</span>
                        <span class="text-slate-500">{{ $c['batch_number'] }} · {{ fmt()->number($c['quantity'], 2) }} · {{ fmt()->date($c['shipped_at']) }}</span></li>
                @empty
                    <li class="px-3 py-3 text-slate-500">You did not ship these batches to anyone.</li>
                @endforelse
            </ul>

            @if ($detail['status'] !== 'completed')
                <x-ui.field label="Notes" for="notice.notes" class="mt-4"><textarea id="notice.notes" wire:model="notes" rows="2" class="form-input" placeholder="What you did with the stock"></textarea></x-ui.field>
            @endif
        @endif
        <x-slot:footer>
            @if ($detail)
                @if ($detail['status'] === 'pending')<button type="button" class="btn-primary" wire:click="act('acknowledge')">Acknowledge</button>@endif
                @if ($detail['status'] === 'acknowledged')<button type="button" class="btn-primary" wire:click="act('complete')">Mark actions complete</button>@endif
            @endif
        </x-slot:footer>
    </x-ui.modal>
</div>

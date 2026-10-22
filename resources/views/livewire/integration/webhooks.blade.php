<div>
    <x-ui.tabs :current="$tab" :tabs="['subscriptions' => 'Subscriptions', 'outbox' => 'Event outbox']"/>

    @if ($tab === 'subscriptions')
        <x-ui.toolbar>
            <p class="text-sm text-slate-600">Each delivery is signed with the subscription's secret and retried with back-off. Verification code samples are in the integration guide.</p>
            <x-slot:actions>@if ($canManage)<button type="button" class="btn-primary" wire:click="create"><x-heroicon-o-plus class="size-4"/>New subscription</button>@endif</x-slot:actions>
        </x-ui.toolbar>
        <div class="card overflow-hidden">
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Name</th><th>URL</th><th>Events</th><th>Delivered / failed</th><th>Last delivered</th><th>Status</th></tr></thead>
                    <tbody>
                        @forelse ($subs as $s)
                            <tr wire:key="w-{{ $s['id'] }}" class="cursor-pointer" wire:click="open('{{ $s['id'] }}')">
                                <td class="font-medium text-navy-900">{{ $s['name'] }}</td>
                                <td class="max-w-[16rem] truncate font-mono text-xs">{{ $s['url'] }}</td>
                                <td class="text-xs">{{ implode(', ', $s['event_types']) }}</td>
                                <td class="text-xs">{{ $s['deliveries']['delivered'] }} / <span class="{{ $s['deliveries']['dead'] ? 'text-rose-700' : '' }}">{{ $s['deliveries']['failed'] + $s['deliveries']['dead'] }}</span></td>
                                <td>{{ $s['last_delivered_at'] ? fmt()->dateTime($s['last_delivered_at']) : '—' }}</td>
                                <td><x-ui.badge :status="$s['status']"/></td>
                            </tr>
                        @empty
                            <x-ui.empty colspan="6" icon="paper-airplane" title="No subscriptions"/>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @else
        <div class="card overflow-hidden">
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Occurred</th><th>Event</th><th>Event id</th><th>Record</th></tr></thead>
                    <tbody>
                        @forelse ($outbox as $e)
                            <tr><td>{{ fmt()->dateTime($e['occurred_at'] ?? null) }}</td><td><x-ui.badge tone="blue">{{ $e['event_type'] ?? $e['type'] ?? '' }}</x-ui.badge></td><td class="font-mono text-xs">{{ $e['event_id'] ?? $e['id'] ?? '' }}</td><td class="text-xs">{{ $e['aggregate_type'] }} {{ $e['aggregate_id'] }}</td></tr>
                        @empty
                            <x-ui.empty colspan="4" icon="inbox" title="No events yet"/>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <x-ui.pager :paginator="$meta"/>
        </div>
    @endif

    <x-ui.modal wire:model="openId" :title="$detail['name'] ?? 'Subscription'" size="xl">
        @if ($detail)
            @if ($secret)
                <div class="mb-4 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900" role="alert">
                    <p class="font-semibold">Signing secret</p><p class="mt-1 break-all rounded bg-white px-3 py-2 font-mono text-xs text-navy-950">{{ $secret['value'] }}</p><p class="mt-1 text-xs">{{ $secret['notice'] }}</p>
                </div>
            @endif
            <x-ui.dl :cols="3" :items="['URL' => $detail['url'], 'Events' => implode(', ', $detail['event_types']), 'Status' => $detail['status'].($detail['status_reason'] ? ' · '.$detail['status_reason'] : ''),
                'Consecutive failures' => $detail['consecutive_failures'], 'Last delivered' => $detail['last_delivered_at'] ? fmt()->dateTime($detail['last_delivered_at']) : null, 'Last failure' => $detail['last_failure_at'] ? fmt()->dateTime($detail['last_failure_at']) : null]"/>
            <div class="mb-2 mt-5 flex items-center justify-between">
                <h3 class="text-sm font-semibold text-navy-950">Deliveries</h3>
                <select wire:model.live="deliveryStatus" class="form-select w-40 py-1.5" aria-label="Status"><option value="">All</option>@foreach (['pending', 'delivering', 'delivered', 'failed', 'dead'] as $s)<option value="{{ $s }}">{{ ucfirst($s) }}</option>@endforeach</select>
            </div>
            <div class="table-wrap max-h-72 rounded-lg border border-slate-200">
                <table class="data-table">
                    <thead><tr><th>Event</th><th class="num">Attempts</th><th>Response</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                        @forelse ($deliveries as $d)
                            <tr><td><span class="text-xs">{{ $d['event_type'] }}</span><span class="block font-mono text-[11px] text-slate-400">{{ $d['event_id'] }}</span></td><td class="num">{{ $d['attempts'] }}</td>
                                <td class="text-xs">{{ $d['response_status'] ?? '' }} {{ $d['error'] ?? '' }}{{ $d['duration_ms'] ? ' · '.$d['duration_ms'].' ms' : '' }}</td><td><x-ui.badge :status="$d['status']"/></td>
                                <td class="text-right">@if ($canManage && in_array($d['status'], ['failed', 'dead'], true))<button type="button" class="link text-xs" wire:click="redeliver('{{ $d['id'] }}')">Redeliver</button>@endif</td></tr>
                        @empty
                            <x-ui.empty colspan="5" title="No deliveries"/>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endif
        <x-slot:footer>
            @if ($detail && $canManage)
                <button type="button" class="btn-light" wire:click="act('test')">Send a test event</button>
                <button type="button" class="btn-light" wire:click="act('secret')" wire:confirm="Issue a new secret? Deliveries are signed with it at once.">New secret</button>
                @if ($detail['status'] === 'active')<button type="button" class="btn-light" wire:click="act('pause')">Pause</button>@else<button type="button" class="btn-primary" wire:click="act('activate')">Activate</button>@endif
            @endif
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal wire:model="form" title="New webhook subscription" size="lg">
        @if ($form)
            <form wire:submit="save" id="wh-form" class="space-y-4">
                <x-ui.field label="Name" for="form.name"><input id="form.name" wire:model="form.name" class="form-input"></x-ui.field>
                <x-ui.field label="HTTPS URL" for="form.url"><input id="form.url" wire:model="form.url" class="form-input font-mono"></x-ui.field>
                <x-ui.field label="Events" error="form.event_types">
                    <div class="grid max-h-64 gap-1 overflow-y-auto rounded-lg border border-slate-200 p-2 sm:grid-cols-2">
                        @foreach ($eventTypes as $e)<label class="flex items-start gap-2 rounded p-1.5 text-sm hover:bg-slate-50"><input type="checkbox" value="{{ $e['type'] }}" wire:model="form.event_types" class="form-check mt-0.5"><span><span class="font-mono text-xs">{{ $e['type'] }}</span><span class="block text-xs text-slate-500">{{ $e['description'] }}</span></span></label>@endforeach
                    </div>
                </x-ui.field>
            </form>
        @endif
        <x-slot:footer><button type="button" class="btn-light" @click="open = null">Cancel</button><button type="submit" form="wh-form" class="btn-primary">Create</button></x-slot:footer>
    </x-ui.modal>
</div>

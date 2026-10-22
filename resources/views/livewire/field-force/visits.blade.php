<div>
    <x-ui.toolbar>
        <x-ui.field label="Status" for="status">
            <select id="status" wire:model.live="status" class="form-select w-40"><option value="">All</option><option value="in_progress">In progress</option><option value="completed">Completed</option><option value="cancelled">Cancelled</option></select>
        </x-ui.field>
        @if ($people->count() > 1)
            <x-ui.field label="Representative" for="representative">
                <select id="representative" wire:model.live="representative" class="form-select w-48"><option value="">Everyone</option>@foreach ($people as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select>
            </x-ui.field>
        @endif
        <x-ui.field label="From" for="from"><input id="from" type="date" wire:model.live="from" class="form-input w-40"></x-ui.field>
        <x-ui.field label="To" for="to"><input id="to" type="date" wire:model.live="to" class="form-input w-40"></x-ui.field>
        <x-slot:actions>@if ($canCapture)<button type="button" class="btn-primary" wire:click="startLog"><x-heroicon-o-plus class="size-4"/>Log a visit</button>@endif</x-slot:actions>
    </x-ui.toolbar>

    <div class="card overflow-hidden">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Visit</th><th>Customer</th><th>Representative</th><th>Type</th><th>Checked in</th><th>Location</th><th>Outcome</th><th>Status</th></tr></thead>
                <tbody>
                    @forelse ($rows as $v)
                        <tr wire:key="v-{{ $v['id'] }}" class="cursor-pointer" wire:click="$set('openId', '{{ $v['id'] }}')">
                            <td class="font-mono text-xs font-semibold">{{ $v['number'] }}</td>
                            <td class="font-medium text-navy-900">{{ $customers[$v['customer_id']] ?? '' }}</td>
                            <td>{{ $people[$v['representative_id']] ?? '' }}</td>
                            <td>{{ str_replace('_', ' ', $v['visit_type']) }}</td>
                            <td>{{ $v['check_in_at'] ? fmt()->dateTime($v['check_in_at']) : '—' }}</td>
                            <td><x-ui.badge :tone="$v['check_in_verification'] === 'verified' ? 'green' : 'amber'">{{ str_replace('_', ' ', $v['check_in_verification'] ?? 'none') }}</x-ui.badge></td>
                            <td>{{ $v['outcome'] ? str_replace('_', ' ', $v['outcome']) : '—' }}</td>
                            <td><x-ui.badge :status="$v['status']"/></td>
                        </tr>
                    @empty
                        <x-ui.empty colspan="8" icon="map-pin" title="No visits"/>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-ui.pager :paginator="$meta"/>
    </div>

    <x-ui.modal wire:model="openId" :title="'Visit '.($detail['number'] ?? '')" size="lg">
        @if ($detail)
            <x-ui.dl :cols="3" :items="[
                'Customer' => $customers[$detail['customer_id']] ?? null, 'Representative' => $people[$detail['representative_id']] ?? null, 'Type' => str_replace('_', ' ', $detail['visit_type']),
                'Checked in' => $detail['check_in_at'] ? fmt()->dateTime($detail['check_in_at']) : null, 'Checked out' => $detail['check_out_at'] ? fmt()->dateTime($detail['check_out_at']) : null,
                'Location check' => str_replace('_', ' ', $detail['check_in_verification'] ?? 'none').($detail['check_in_distance_m'] ? ' · '.round($detail['check_in_distance_m']).' m away' : ''),
                'Outcome' => $detail['outcome'] ? str_replace('_', ' ', $detail['outcome']) : null, 'Next action' => $detail['next_action'] ?? null,
                'Follow-up' => ($detail['follow_up_date'] ?? null) ? fmt()->date($detail['follow_up_date']) : null,
            ]"/>
            @foreach (['objectives' => 'Objectives', 'discussion_notes' => 'Discussion', 'customer_feedback' => 'Customer feedback', 'competitor_information' => 'Competitors'] as $k => $label)
                @if ($detail[$k] ?? null)<p class="mt-3 text-sm"><strong>{{ $label }}:</strong> {{ $detail[$k] }}</p>@endif
            @endforeach
            @if (! empty($detail['activities']))
                <p class="mb-1 mt-4 text-xs font-semibold uppercase text-slate-400">Activities</p>
                <ul class="divide-y divide-slate-100 rounded-lg border border-slate-200 text-sm">
                    @foreach ($detail['activities'] as $a)<li class="px-3 py-2"><x-ui.badge tone="blue">{{ str_replace('_', ' ', $a['activity_type']) }}</x-ui.badge> {{ $names[$a['product_id'] ?? ''] ?? '' }} <span class="text-slate-600">{{ $a['notes'] }}</span></li>@endforeach
                </ul>
            @endif
            @if ($detail['status'] === 'in_progress' && $canCapture)
                <div class="mt-4 flex justify-end"><button type="button" class="btn-danger btn-sm" wire:click="cancelVisit('Cancelled from the web')" wire:confirm="Cancel this visit?">Cancel visit</button></div>
            @endif
        @endif
    </x-ui.modal>

    <x-ui.modal wire:model="log" title="Log a visit" size="lg">
        @if ($log)
            <p class="mb-4 rounded-lg bg-amber-50 p-3 text-xs text-amber-900">Visits logged on the web have no device location, so they are recorded as unverified. Use the field app at the customer's premises for verified check-ins.</p>
            <form wire:submit="saveLog" id="log-form" class="grid gap-4 sm:grid-cols-2">
                <x-ui.field label="Customer" for="log.customer_id"><select id="log.customer_id" wire:model="log.customer_id" class="form-select"><option value="">Choose…</option>@foreach ($customers as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select></x-ui.field>
                <x-ui.field label="Type" for="log.visit_type"><select id="log.visit_type" wire:model="log.visit_type" class="form-select">@foreach ($types as $t)<option value="{{ $t }}">{{ ucfirst(str_replace('_', ' ', $t)) }}</option>@endforeach</select></x-ui.field>
                <x-ui.field label="Objectives" for="log.objectives" class="sm:col-span-2"><input id="log.objectives" wire:model="log.objectives" class="form-input"></x-ui.field>
                <x-ui.field label="What was discussed" for="log.discussion_notes" class="sm:col-span-2"><textarea id="log.discussion_notes" wire:model="log.discussion_notes" rows="3" class="form-input"></textarea></x-ui.field>
                <x-ui.field label="Activity" for="log.activities"><select wire:model="log.activity" class="form-select">@foreach ($activityTypes as $t)<option value="{{ $t }}">{{ ucfirst(str_replace('_', ' ', $t)) }}</option>@endforeach</select></x-ui.field>
                <x-ui.field label="Activity notes" for="log.activity_notes"><input wire:model="log.activity_notes" class="form-input" placeholder="Optional"></x-ui.field>
                <x-ui.field label="Outcome" for="log.outcome"><select id="log.outcome" wire:model="log.outcome" class="form-select">@foreach ($outcomes as $o)<option value="{{ $o }}">{{ ucfirst(str_replace('_', ' ', $o)) }}</option>@endforeach</select></x-ui.field>
                <x-ui.field label="Next action" for="log.next_action"><input id="log.next_action" wire:model="log.next_action" class="form-input"></x-ui.field>
            </form>
        @endif
        <x-slot:footer><button type="button" class="btn-light" @click="open = null">Cancel</button><button type="submit" form="log-form" class="btn-primary">Log visit</button></x-slot:footer>
    </x-ui.modal>
</div>

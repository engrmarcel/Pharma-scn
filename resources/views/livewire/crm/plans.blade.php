<div>
    <x-ui.toolbar>
        <x-ui.field label="Status" for="status">
            <select id="status" wire:model.live="status" class="form-select w-40"><option value="">All</option>@foreach (['draft', 'submitted', 'approved', 'rejected', 'cancelled'] as $s)<option value="{{ $s }}">{{ ucfirst($s) }}</option>@endforeach</select>
        </x-ui.field>
        <x-slot:actions>@if ($can['capture'])<button type="button" class="btn-primary" wire:click="create"><x-heroicon-o-plus class="size-4"/>New plan</button>@endif</x-slot:actions>
    </x-ui.toolbar>

    <div class="card overflow-hidden">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Representative</th><th>Period</th><th class="num">Planned</th><th class="num">Visited</th><th class="num">Missed</th><th class="num">Adherence</th><th>Status</th></tr></thead>
                <tbody>
                    @forelse ($rows as $p)
                        <tr wire:key="p-{{ $p['id'] }}" class="cursor-pointer" wire:click="$set('openId', '{{ $p['id'] }}')">
                            <td class="font-medium text-navy-900">{{ $people[$p['representative_id']] ?? '' }}</td>
                            <td>{{ fmt()->date($p['period_start']) }} – {{ fmt()->date($p['period_end']) }}</td>
                            <td class="num">{{ $p['adherence']['planned'] }}</td>
                            <td class="num text-emerald-700">{{ $p['adherence']['visited'] }}</td>
                            <td class="num {{ $p['adherence']['missed'] ? 'text-rose-700' : '' }}">{{ $p['adherence']['missed'] }}</td>
                            <td class="num font-semibold">{{ $p['adherence']['adherence_percent'] !== null ? $p['adherence']['adherence_percent'].'%' : '—' }}</td>
                            <td><x-ui.badge :status="$p['status']"/></td>
                        </tr>
                    @empty
                        <x-ui.empty colspan="7" icon="calendar-days" title="No visit plans"/>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-ui.pager :paginator="$meta"/>
    </div>

    <x-ui.modal wire:model="openId" title="Visit plan" size="lg">
        @if ($detail)
            <p class="mb-3 text-sm text-slate-600">{{ $people[$detail['representative_id']] ?? '' }} · {{ fmt()->date($detail['period_start']) }} – {{ fmt()->date($detail['period_end']) }} · <x-ui.badge :status="$detail['status']"/></p>
            @if ($detail['decision_notes'])<p class="mb-3 text-sm"><strong>Supervisor:</strong> {{ $detail['decision_notes'] }}</p>@endif
            <ul class="divide-y divide-slate-100 rounded-lg border border-slate-200 text-sm">
                @foreach (collect($detail['items'])->sortBy(['planned_date', 'sequence']) as $i)
                    <li class="flex flex-wrap justify-between gap-2 px-3 py-2"><span><span class="text-slate-500">{{ fmt()->date($i['planned_date']) }}</span> · <span class="font-medium">{{ $customers[$i['customer_id']] ?? '' }}</span>
                        @if ($i['objective'])<span class="muted block">{{ $i['objective'] }}</span>@endif</span><x-ui.badge :status="$i['status']"/></li>
                @endforeach
            </ul>
            @if ($detail['hidden_items'])<p class="mt-2 text-xs text-slate-500">{{ $detail['hidden_items'] }} calls on customers outside your reach are not shown.</p>@endif
            @if (($detail['status'] === 'submitted' && $can['approve'] && $detail['representative_id'] !== $me) || in_array($detail['status'], ['draft', 'submitted', 'approved'], true))
                <x-ui.field label="Notes" for="decision.notes" class="mt-4"><input id="decision.notes" wire:model="decisionNotes" class="form-input"></x-ui.field>
            @endif
        @endif
        <x-slot:footer>
            @if ($detail)
                @if ($detail['status'] === 'draft' && $detail['representative_id'] === $me)<button type="button" class="btn-primary" wire:click="act('submit')">Submit for approval</button>@endif
                @if ($detail['status'] === 'submitted' && $can['approve'] && $detail['representative_id'] !== $me)
                    <button type="button" class="btn-danger" wire:click="act('reject')">Send back</button>
                    <button type="button" class="btn-primary" wire:click="act('approve')">Approve</button>
                @endif
                @if (in_array($detail['status'], ['draft', 'submitted', 'approved'], true))<button type="button" class="btn-light" wire:click="act('cancel')">Cancel plan</button>@endif
            @endif
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal wire:model="plan" title="New visit plan" size="xl">
        @if ($plan)
            <form wire:submit="save" id="plan-form" class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-3">
                    <x-ui.field label="From" for="plan.period_start"><input id="plan.period_start" type="date" wire:model="plan.period_start" class="form-input"></x-ui.field>
                    <x-ui.field label="To" for="plan.period_end"><input id="plan.period_end" type="date" wire:model="plan.period_end" class="form-input"></x-ui.field>
                    <x-ui.field label="Notes" for="plan.notes"><input id="plan.notes" wire:model="plan.notes" class="form-input"></x-ui.field>
                </div>
                @error('plan.items') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
                @foreach ($plan['items'] as $i => $it)
                    <div class="grid items-start gap-2 sm:grid-cols-[minmax(0,1fr)_10rem_minmax(0,1fr)_auto]" wire:key="pi-{{ $i }}">
                        <x-ui.field :for="'plan.items.'.$i.'.customer_id'"><select wire:model="plan.items.{{ $i }}.customer_id" class="form-select py-2"><option value="">Customer…</option>@foreach ($customers as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select></x-ui.field>
                        <x-ui.field :for="'plan.items.'.$i.'.planned_date'"><input type="date" wire:model="plan.items.{{ $i }}.planned_date" class="form-input py-2"></x-ui.field>
                        <input wire:model="plan.items.{{ $i }}.objective" class="form-input py-2" placeholder="Objective">
                        <button type="button" class="p-2 text-slate-400 hover:text-rose-600" wire:click="removeItem({{ $i }})" aria-label="Remove"><x-heroicon-o-trash class="size-4"/></button>
                    </div>
                @endforeach
                <div class="flex gap-4 text-sm"><button type="button" class="link" wire:click="addItem">+ Add a call</button><button type="button" class="link" wire:click="suggest">Add suggested customers</button></div>
            </form>
        @endif
        <x-slot:footer><button type="button" class="btn-light" @click="open = null">Cancel</button><button type="submit" form="plan-form" class="btn-primary">Save draft</button></x-slot:footer>
    </x-ui.modal>
</div>

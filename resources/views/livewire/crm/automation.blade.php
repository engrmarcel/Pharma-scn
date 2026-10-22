<div class="grid gap-6 lg:grid-cols-2">
    <form wire:submit="save">
        <x-ui.card title="Automatic follow-ups" icon="bolt">
            <div class="space-y-4">
                <label class="flex items-start gap-3"><input type="checkbox" wire:model="form.missed_plan_items" class="form-check mt-0.5"><span class="text-sm"><span class="font-medium text-navy-900">Missed plan items</span><span class="muted block">Mark planned calls that passed without a visit as missed, and create a follow-up.</span></span></label>
                <label class="flex items-start gap-3"><input type="checkbox" wire:model="form.overdue_visits" class="form-check mt-0.5"><span class="text-sm"><span class="font-medium text-navy-900">Overdue visits</span><span class="muted block">Create a follow-up when a customer's call frequency is exceeded.</span></span></label>
                <x-ui.field label="Stale opportunity after (days)" for="form.stale_opportunity_days" hint="Empty: off."><input id="form.stale_opportunity_days" type="number" min="1" wire:model="form.stale_opportunity_days" class="form-input w-32"></x-ui.field>
                <x-ui.field label="Escalate to the supervisor after (days overdue)" for="form.escalation_days" hint="Empty: off."><input id="form.escalation_days" type="number" min="0" wire:model="form.escalation_days" class="form-input w-32"></x-ui.field>
            </div>
            <div class="mt-6 flex justify-between">
                <button type="button" class="btn-light" wire:click="run"><x-heroicon-o-play class="size-4"/>Run now</button>
                <button class="btn-primary">Save settings</button>
            </div>
            @if ($lastRun)
                <p class="mt-4 rounded-lg bg-slate-50 p-3 text-sm">{{ collect($lastRun)->map(fn ($v, $k) => str_replace('_', ' ', $k).': '.$v)->implode(' · ') }}</p>
            @endif
            <p class="mt-3 text-xs text-slate-500">The scheduler runs these rules every day; "Run now" applies them immediately.</p>
        </x-ui.card>
    </form>

    @if ($canHandover)
        <form wire:submit="transfer">
            <x-ui.card title="Hand over a representative's work" icon="arrows-right-left">
                <p class="mb-4 text-sm text-slate-600">Moves customers, open follow-ups, open opportunities and future plan items from one person to another, for example when someone leaves.</p>
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.field label="From" for="handover.from_user_id"><select id="handover.from_user_id" wire:model="handover.from_user_id" class="form-select"><option value="">Choose…</option>@foreach ($people as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select></x-ui.field>
                    <x-ui.field label="To" for="handover.to_user_id"><select id="handover.to_user_id" wire:model="handover.to_user_id" class="form-select"><option value="">Choose…</option>@foreach ($people as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select></x-ui.field>
                    <x-ui.field label="Only in territory (optional)" for="handover.territory_id" class="sm:col-span-2"><select id="handover.territory_id" wire:model="handover.territory_id" class="form-select"><option value="">All territories</option>@foreach ($territories as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select></x-ui.field>
                </div>
                <div class="mt-6 flex justify-end"><button class="btn-primary" wire:confirm="Hand over this work?">Hand over</button></div>
            </x-ui.card>
        </form>
    @endif
</div>

<div>
    <x-ui.toolbar>
        <x-ui.field label="Status" for="status">
            <select id="status" wire:model.live="status" class="form-select w-36"><option value="open">Open</option><option value="done">Done</option><option value="cancelled">Cancelled</option><option value="">All</option></select>
        </x-ui.field>
        <x-ui.field label="Due by" for="dueBefore"><input id="dueBefore" type="date" wire:model.live="dueBefore" class="form-input w-40"></x-ui.field>
        <x-slot:actions>@if ($canCapture)<button type="button" class="btn-primary" wire:click="create"><x-heroicon-o-plus class="size-4"/>New follow-up</button>@endif</x-slot:actions>
    </x-ui.toolbar>

    <div class="card overflow-hidden">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Due</th><th>Follow-up</th><th>Customer</th><th>Assigned to</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    @forelse ($rows as $f)
                        @php $late = $f['status'] === 'open' && substr($f['due_on'], 0, 10) < $today; @endphp
                        <tr wire:key="f-{{ $f['id'] }}">
                            <td class="{{ $late ? 'font-semibold text-rose-700' : '' }}">{{ fmt()->date($f['due_on']) }}</td>
                            <td><span class="font-medium text-navy-900">{{ $f['title'] }}</span>@if ($f['notes'])<span class="muted block max-w-md truncate">{{ $f['notes'] }}</span>@endif
                                @if ($f['outcome'] ?? null)<span class="block text-xs text-emerald-700">{{ $f['outcome'] }}</span>@endif</td>
                            <td>{{ $customers[$f['customer_id']] ?? '' }}</td>
                            <td>{{ $people[$f['assigned_to']] ?? '' }}</td>
                            <td><x-ui.badge :status="$late ? 'overdue' : $f['status']">{{ $late ? 'overdue' : $f['status'] }}</x-ui.badge></td>
                            <td class="whitespace-nowrap text-right">@if ($canCapture && $f['status'] === 'open')
                                <button type="button" class="link text-xs" wire:click="start('{{ $f['id'] }}', 'complete')">Done</button>
                                <button type="button" class="link ml-2 text-xs text-slate-500" wire:click="start('{{ $f['id'] }}', 'cancel')">Cancel</button>
                            @endif</td>
                        </tr>
                    @empty
                        <x-ui.empty colspan="6" icon="bell" title="No follow-ups"/>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-ui.pager :paginator="$meta"/>
    </div>

    <x-ui.modal wire:model="closing" :title="($closing['action'] ?? '') === 'complete' ? 'Mark as done' : 'Cancel follow-up'" size="sm">
        @if ($closing)
            <form wire:submit="finish" id="close-form"><x-ui.field :label="$closing['action'] === 'complete' ? 'Outcome' : 'Reason (optional)'" for="closing.outcome"><textarea id="closing.outcome" wire:model="closing.outcome" rows="2" class="form-input"></textarea></x-ui.field></form>
        @endif
        <x-slot:footer><button type="button" class="btn-light" @click="open = null">Back</button><button type="submit" form="close-form" class="btn-primary">Save</button></x-slot:footer>
    </x-ui.modal>

    <x-ui.modal wire:model="form" title="New follow-up" size="md">
        @if ($form)
            <form wire:submit="save" id="fu-form" class="grid gap-4 sm:grid-cols-2">
                <x-ui.field label="Customer" for="form.customer_id"><select id="form.customer_id" wire:model="form.customer_id" class="form-select"><option value="">Choose…</option>@foreach ($customers as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select></x-ui.field>
                <x-ui.field label="Due" for="form.due_on"><input id="form.due_on" type="date" wire:model="form.due_on" class="form-input"></x-ui.field>
                <x-ui.field label="What to do" for="form.title" class="sm:col-span-2"><input id="form.title" wire:model="form.title" class="form-input"></x-ui.field>
                <x-ui.field label="Notes" for="form.notes" class="sm:col-span-2"><textarea id="form.notes" wire:model="form.notes" rows="2" class="form-input"></textarea></x-ui.field>
                <x-ui.field label="Assign to" for="form.assigned_to"><select id="form.assigned_to" wire:model="form.assigned_to" class="form-select"><option value="">Me</option>@foreach ($people as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select></x-ui.field>
            </form>
        @endif
        <x-slot:footer><button type="button" class="btn-light" @click="open = null">Cancel</button><button type="submit" form="fu-form" class="btn-primary">Add</button></x-slot:footer>
    </x-ui.modal>
</div>

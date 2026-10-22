<div>
    <x-ui.toolbar>
        <x-ui.field label="Customer" for="customer"><select id="customer" wire:model.live="customer" class="form-select w-56"><option value="">All</option>@foreach ($customers as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select></x-ui.field>
        <x-ui.field label="Channel" for="channel"><select id="channel" wire:model.live="channel" class="form-select w-36"><option value="">All</option>@foreach ($channels as $c)<option value="{{ $c }}">{{ ucfirst($c) }}</option>@endforeach</select></x-ui.field>
        <x-slot:actions>@if ($canCapture)<button type="button" class="btn-primary" wire:click="create"><x-heroicon-o-plus class="size-4"/>Log interaction</button>@endif</x-slot:actions>
    </x-ui.toolbar>

    <div class="card overflow-hidden">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>When</th><th>Customer</th><th>Channel</th><th>Subject</th><th>By</th></tr></thead>
                <tbody>
                    @forelse ($rows as $i)
                        <tr wire:key="i-{{ $i['id'] }}">
                            <td class="whitespace-nowrap">{{ fmt()->dateTime($i['occurred_at']) }}</td>
                            <td class="font-medium text-navy-900">{{ $customers[$i['customer_id']] ?? '' }}</td>
                            <td><x-ui.badge tone="blue">{{ $i['channel'] }}</x-ui.badge> <span class="text-xs text-slate-500">{{ $i['direction'] }}</span></td>
                            <td><span class="font-medium">{{ $i['subject'] }}</span>@if ($i['summary'])<span class="muted block max-w-lg truncate">{{ $i['summary'] }}</span>@endif</td>
                            <td>{{ $people[$i['representative_id']] ?? '' }}</td>
                        </tr>
                    @empty
                        <x-ui.empty colspan="5" icon="chat-bubble-left-ellipsis" title="No interactions"/>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-ui.pager :paginator="$meta"/>
    </div>

    <x-ui.modal wire:model="form" title="Log an interaction" size="md">
        @if ($form)
            <form wire:submit="save" id="int-form" class="grid gap-4 sm:grid-cols-2">
                <x-ui.field label="Customer" for="form.customer_id"><select id="form.customer_id" wire:model.live="form.customer_id" class="form-select"><option value="">Choose…</option>@foreach ($customers as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select></x-ui.field>
                <x-ui.field label="Contact" for="form.contact_id"><select id="form.contact_id" wire:model="form.contact_id" class="form-select"><option value="">None</option>@foreach ($contacts as $c)<option value="{{ $c['id'] }}">{{ $c['name'] }}{{ $c['contact_consent'] !== 'granted' ? ' (no consent)' : '' }}</option>@endforeach</select></x-ui.field>
                <x-ui.field label="Channel" for="form.channel"><select id="form.channel" wire:model="form.channel" class="form-select">@foreach ($channels as $c)<option value="{{ $c }}">{{ ucfirst($c) }}</option>@endforeach</select></x-ui.field>
                <x-ui.field label="Direction" for="form.direction"><select id="form.direction" wire:model="form.direction" class="form-select"><option value="outbound">We contacted them</option><option value="inbound">They contacted us</option></select></x-ui.field>
                <x-ui.field label="When" for="form.occurred_at"><input id="form.occurred_at" type="datetime-local" wire:model="form.occurred_at" class="form-input"></x-ui.field>
                <x-ui.field label="Subject" for="form.subject"><input id="form.subject" wire:model="form.subject" class="form-input"></x-ui.field>
                <x-ui.field label="Summary" for="form.summary" class="sm:col-span-2"><textarea id="form.summary" wire:model="form.summary" rows="3" class="form-input"></textarea></x-ui.field>
            </form>
        @endif
        <x-slot:footer><button type="button" class="btn-light" @click="open = null">Cancel</button><button type="submit" form="int-form" class="btn-primary">Log</button></x-slot:footer>
    </x-ui.modal>
</div>

<div>
    <div class="mb-6 grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
        @foreach ($stages as $s)
            <button type="button" wire:click="$set('stage', '{{ $stage === $s ? '' : $s }}')" class="text-left">
                <x-ui.stat :label="ucfirst($s)" :value="($pipeline[$s]['count'] ?? 0)" :hint="fmt()->money($pipeline[$s]['weighted_value'] ?? 0, $currency).' weighted'"
                           :tone="$s === 'won' ? 'green' : ($s === 'lost' ? 'red' : 'navy')" class="{{ $stage === $s ? 'ring-2 ring-[var(--brand-primary)]' : '' }}"/>
            </button>
        @endforeach
    </div>

    <x-ui.toolbar>
        <p class="text-sm text-slate-600">{{ $stage ? 'Showing '.$stage.' only.' : 'All stages.' }} Click a stage to filter.</p>
        <x-slot:actions>@if ($canCapture)<button type="button" class="btn-primary" wire:click="create"><x-heroicon-o-plus class="size-4"/>New opportunity</button>@endif</x-slot:actions>
    </x-ui.toolbar>

    <div class="card overflow-hidden">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Opportunity</th><th>Customer or lead</th><th>Stage</th><th class="num">Value</th><th class="num">Probability</th><th>Close</th><th>Next step</th><th></th></tr></thead>
                <tbody>
                    @forelse ($rows as $o)
                        <tr wire:key="o-{{ $o['id'] }}">
                            <td class="font-medium text-navy-900">{{ $o['title'] }}</td>
                            <td>{{ $customers[$o['customer_id'] ?? ''] ?? ($o['lead_name'] ?? '—') }}@if (! $o['customer_id'])<x-ui.badge tone="amber" class="ml-1">lead</x-ui.badge>@endif</td>
                            <td><x-ui.badge :status="$o['stage'] === 'won' ? 'won' : ($o['stage'] === 'lost' ? 'lost' : 'in_progress')">{{ $o['stage'] }}</x-ui.badge></td>
                            <td class="num">{{ $o['expected_value'] !== null ? fmt()->money($o['expected_value'], $o['currency_code'] ?? $currency) : '—' }}</td>
                            <td class="num">{{ $o['probability'] !== null ? $o['probability'].'%' : '—' }}</td>
                            <td>{{ $o['expected_close_date'] ? fmt()->date($o['expected_close_date']) : '—' }}</td>
                            <td class="text-xs">{{ $o['next_step'] }}@if ($o['next_step_date']) <span class="text-slate-400">by {{ fmt()->date($o['next_step_date']) }}</span>@endif</td>
                            <td class="whitespace-nowrap text-right">@if ($canCapture)
                                <button type="button" class="link text-xs" wire:click="edit(@js($o))">Edit</button>
                                @if (! $o['customer_id'])<button type="button" class="link ml-2 text-xs" wire:click="startConvert('{{ $o['id'] }}', @js($o['lead_name'] ?? $o['title']))">Convert</button>@endif
                            @endif</td>
                        </tr>
                    @empty
                        <x-ui.empty colspan="8" icon="sparkles" title="No opportunities"/>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-ui.pager :paginator="$meta"/>
    </div>

    <x-ui.modal wire:model="form" :title="($form['id'] ?? null) ? 'Edit opportunity' : 'New opportunity'" size="lg">
        @if ($form)
            <form wire:submit="save" id="opp-form" class="grid gap-4 sm:grid-cols-2">
                <x-ui.field label="Title" for="form.title" class="sm:col-span-2"><input id="form.title" wire:model="form.title" class="form-input"></x-ui.field>
                @unless ($form['id'])
                    <x-ui.field label="Customer" for="form.customer_id"><select id="form.customer_id" wire:model="form.customer_id" class="form-select"><option value="">New lead (not a customer yet)</option>@foreach ($customers as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select></x-ui.field>
                    <x-ui.field label="Lead name" for="form.lead_name"><input id="form.lead_name" wire:model="form.lead_name" class="form-input"></x-ui.field>
                @endunless
                <x-ui.field label="Stage" for="form.stage"><select id="form.stage" wire:model.live="form.stage" class="form-select">@foreach (($form['id'] ? $stages : ['lead', 'qualified', 'proposal', 'negotiation']) as $s)<option value="{{ $s }}">{{ ucfirst($s) }}</option>@endforeach</select></x-ui.field>
                <x-ui.field :label="'Expected value ('.$currency.')'" for="form.expected_value"><input id="form.expected_value" wire:model="form.expected_value" inputmode="decimal" class="form-input"></x-ui.field>
                <x-ui.field label="Probability %" for="form.probability"><input id="form.probability" type="number" min="0" max="100" wire:model="form.probability" class="form-input"></x-ui.field>
                <x-ui.field label="Expected close" for="form.expected_close_date"><input id="form.expected_close_date" type="date" wire:model="form.expected_close_date" class="form-input"></x-ui.field>
                <x-ui.field label="Next step" for="form.next_step"><input id="form.next_step" wire:model="form.next_step" class="form-input"></x-ui.field>
                <x-ui.field label="By" for="form.next_step_date"><input id="form.next_step_date" type="date" wire:model="form.next_step_date" class="form-input"></x-ui.field>
                @if ($form['stage'] === 'lost')<x-ui.field label="Why lost" for="form.lost_reason" class="sm:col-span-2"><input id="form.lost_reason" wire:model="form.lost_reason" class="form-input"></x-ui.field>@endif
                <x-ui.field label="Notes" for="form.notes" class="sm:col-span-2"><textarea id="form.notes" wire:model="form.notes" rows="2" class="form-input"></textarea></x-ui.field>
            </form>
        @endif
        <x-slot:footer><button type="button" class="btn-light" @click="open = null">Cancel</button><button type="submit" form="opp-form" class="btn-primary">Save</button></x-slot:footer>
    </x-ui.modal>

    <x-ui.modal wire:model="converting" title="Convert the lead into a customer" size="sm">
        @if ($converting)
            <form wire:submit="convert" id="conv-form" class="space-y-4">
                <x-ui.field label="Customer code" for="converting.code"><input id="converting.code" wire:model="converting.code" class="form-input font-mono uppercase"></x-ui.field>
                <x-ui.field label="Name" for="converting.name"><input id="converting.name" wire:model="converting.name" class="form-input"></x-ui.field>
                <x-ui.field label="Type" for="converting.type"><select id="converting.type" wire:model="converting.type" class="form-select">@foreach ($types as $t)<option value="{{ $t }}">{{ ucfirst(str_replace('_', ' ', $t)) }}</option>@endforeach</select></x-ui.field>
            </form>
        @endif
        <x-slot:footer><button type="button" class="btn-light" @click="open = null">Cancel</button><button type="submit" form="conv-form" class="btn-primary">Convert</button></x-slot:footer>
    </x-ui.modal>
</div>

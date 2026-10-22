<div>
    <x-ui.tabs :current="$tab" :tabs="['messages' => 'Approved messages', 'records' => 'What was presented']"/>

    @if ($tab === 'messages')
        <x-ui.toolbar>
            @if ($canManage)
                <x-ui.field label="Status" for="status"><select id="status" wire:model.live="status" class="form-select w-36"><option value="">All</option><option value="draft">Draft</option><option value="approved">Approved</option><option value="retired">Retired</option></select></x-ui.field>
            @else
                <p class="text-sm text-slate-600">Only approved, current messages may be presented to customers.</p>
            @endif
            <x-slot:actions>@if ($canManage)<button type="button" class="btn-primary" wire:click="create"><x-heroicon-o-plus class="size-4"/>New message</button>@endif</x-slot:actions>
        </x-ui.toolbar>
        <div class="grid gap-4 md:grid-cols-2">
            @forelse ($messages as $m)
                <div class="card p-5" wire:key="m-{{ $m['id'] }}">
                    <div class="flex items-start justify-between gap-2">
                        <div><p class="text-xs font-semibold text-gold-600">{{ $names[$m['product_id']] ?? '' }}</p><h3 class="font-semibold text-navy-950">{{ $m['title'] }}</h3></div>
                        <x-ui.badge :status="$m['status']"/>
                    </div>
                    <p class="mt-2 whitespace-pre-line text-sm text-slate-700">{{ $m['key_message'] }}</p>
                    <p class="mt-2 text-xs text-slate-500">{{ $m['approval_reference'] ? 'Approval '.$m['approval_reference'] : 'No approval reference' }}{{ $m['valid_until'] ? ' · valid until '.fmt()->date($m['valid_until']) : '' }}</p>
                    @if ($canManage)
                        <div class="mt-3 flex gap-3 text-xs">
                            @if ($m['status'] === 'draft')<button type="button" class="link" wire:click="edit(@js($m), @js($names[$m['product_id']] ?? ''))">Edit</button><button type="button" class="link" wire:click="act('{{ $m['id'] }}', 'approve')">Approve</button>@endif
                            @if ($m['status'] === 'approved')<button type="button" class="link text-rose-700" wire:click="act('{{ $m['id'] }}', 'retire')">Retire</button>@endif
                        </div>
                    @endif
                </div>
            @empty
                <x-ui.card class="md:col-span-2"><x-ui.empty icon="presentation-chart-bar" title="No messages"/></x-ui.card>
            @endforelse
        </div>
    @else
        <div class="card overflow-hidden">
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>When</th><th>Customer</th><th>Product</th><th class="num">Messages</th><th>Reception</th><th class="num">Samples</th></tr></thead>
                    <tbody>
                        @forelse ($records as $r)
                            <tr><td>{{ fmt()->dateTime($r['recorded_at']) }}</td><td class="font-medium text-navy-900">{{ $customers[$r['customer_id']] ?? '' }}</td><td>{{ $names[$r['product_id']] ?? '' }}</td>
                                <td class="num">{{ count((array) ($r['message_ids'] ?? [])) }}</td><td>{{ str_replace('_', ' ', $r['reception'] ?? '—') }}</td>
                                <td class="num">{{ $r['samples_quantity'] ? fmt()->number($r['samples_quantity'], 0).' · '.$r['sample_batch_number'] : '—' }}</td></tr>
                        @empty
                            <x-ui.empty colspan="6" icon="presentation-chart-bar" title="No detailing recorded"/>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <x-ui.pager :paginator="$meta"/>
        </div>
    @endif

    <x-ui.modal wire:model="form" :title="($form['id'] ?? null) ? 'Edit message' : 'New detailing message'" size="md">
        @if ($form)
            <form wire:submit="save" id="msg-form" class="space-y-4">
                @unless ($form['id'])<x-ui.field label="Product" for="form.product_id">@include('livewire.partials.product-picker', ['target' => 'message', 'selected' => $form['product_name']])</x-ui.field>@endunless
                <x-ui.field label="Title" for="form.title"><input id="form.title" wire:model="form.title" class="form-input"></x-ui.field>
                <x-ui.field label="Key message" for="form.key_message"><textarea id="form.key_message" wire:model="form.key_message" rows="4" class="form-input"></textarea></x-ui.field>
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.field label="Approval reference" for="form.approval_reference"><input id="form.approval_reference" wire:model="form.approval_reference" class="form-input"></x-ui.field>
                    <x-ui.field label="Valid until" for="form.valid_until"><input id="form.valid_until" type="date" wire:model="form.valid_until" class="form-input"></x-ui.field>
                </div>
            </form>
        @endif
        <x-slot:footer><button type="button" class="btn-light" @click="open = null">Cancel</button><button type="submit" form="msg-form" class="btn-primary">Save</button></x-slot:footer>
    </x-ui.modal>
</div>

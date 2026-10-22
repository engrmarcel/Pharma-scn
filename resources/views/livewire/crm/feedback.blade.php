<div>
    @if ($notice)<div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-900" role="alert">{{ $notice }}</div>@endif

    <x-ui.toolbar>
        <x-ui.field label="Status" for="status"><select id="status" wire:model.live="status" class="form-select w-36"><option value="open">Open</option><option value="in_review">In review</option><option value="resolved">Resolved</option><option value="">All</option></select></x-ui.field>
        <x-ui.field label="Category" for="category"><select id="category" wire:model.live="category" class="form-select w-44"><option value="">All</option>@foreach ($categories as $c)<option value="{{ $c }}">{{ ucfirst(str_replace('_', ' ', $c)) }}</option>@endforeach</select></x-ui.field>
        <x-slot:actions>@if ($canCapture)<button type="button" class="btn-primary" wire:click="create"><x-heroicon-o-plus class="size-4"/>Record feedback</button>@endif</x-slot:actions>
    </x-ui.toolbar>

    <div class="card overflow-hidden">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Received</th><th>Customer</th><th>Category</th><th>Product</th><th>Feedback</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    @forelse ($rows as $f)
                        <tr wire:key="f-{{ $f['id'] }}">
                            <td class="whitespace-nowrap">{{ fmt()->date($f['created_at']) }}</td>
                            <td class="font-medium text-navy-900">{{ $customers[$f['customer_id']] ?? '' }}</td>
                            <td>{{ str_replace('_', ' ', $f['category']) }}@if ($f['rating'])<span class="muted block">{{ str_repeat('★', $f['rating']) }}</span>@endif
                                @if ($f['possible_adverse_event'])<x-ui.badge tone="red" class="mt-1">possible adverse event</x-ui.badge>@endif</td>
                            <td>{{ $names[$f['product_id'] ?? ''] ?? '—' }}</td>
                            <td class="max-w-md"><span class="line-clamp-2">{{ $f['description'] }}</span>@if ($f['resolution'] ?? null)<span class="block text-xs text-emerald-700">{{ $f['resolution'] }}</span>@endif</td>
                            <td><x-ui.badge :status="$f['status'] === 'in_review' ? 'in_progress' : ($f['status'] === 'resolved' ? 'resolved' : 'pending')">{{ str_replace('_', ' ', $f['status']) }}</x-ui.badge></td>
                            <td class="whitespace-nowrap text-right">@if ($canManage && $f['status'] !== 'resolved')
                                @if ($f['status'] === 'open')<button type="button" class="link text-xs" wire:click="start('{{ $f['id'] }}', 'review')">Review</button>@endif
                                <button type="button" class="link ml-2 text-xs" wire:click="start('{{ $f['id'] }}', 'resolve')">Resolve</button>
                            @endif</td>
                        </tr>
                    @empty
                        <x-ui.empty colspan="7" icon="megaphone" title="No feedback"/>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-ui.pager :paginator="$meta"/>
    </div>

    <x-ui.modal wire:model="progress" :title="($progress['action'] ?? '') === 'resolve' ? 'Resolve feedback' : 'Start review'" size="sm">
        @if ($progress)
            <form wire:submit="apply" id="prog-form" class="space-y-4">
                <x-ui.field :label="$progress['action'] === 'resolve' ? 'Resolution' : 'Notes (optional)'" for="progress.resolution"><textarea id="progress.resolution" wire:model="progress.resolution" rows="3" class="form-input"></textarea></x-ui.field>
                <x-ui.field label="Pharmacovigilance reference (if reported)" for="progress.pharmacovigilance_reference"><input id="progress.pharmacovigilance_reference" wire:model="progress.pharmacovigilance_reference" class="form-input"></x-ui.field>
            </form>
        @endif
        <x-slot:footer><button type="button" class="btn-light" @click="open = null">Cancel</button><button type="submit" form="prog-form" class="btn-primary">Save</button></x-slot:footer>
    </x-ui.modal>

    <x-ui.modal wire:model="form" title="Record feedback" size="md">
        @if ($form)
            <form wire:submit="save" id="fb-form" class="grid gap-4 sm:grid-cols-2">
                <x-ui.field label="Customer" for="form.customer_id"><select id="form.customer_id" wire:model="form.customer_id" class="form-select"><option value="">Choose…</option>@foreach ($customers as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select></x-ui.field>
                <x-ui.field label="Category" for="form.category"><select id="form.category" wire:model="form.category" class="form-select">@foreach ($categories as $c)<option value="{{ $c }}">{{ ucfirst(str_replace('_', ' ', $c)) }}</option>@endforeach</select></x-ui.field>
                <x-ui.field label="Product (optional)" for="form.product_id" class="sm:col-span-2">@include('livewire.partials.product-picker', ['target' => 'feedback', 'selected' => $form['product_name']])</x-ui.field>
                <x-ui.field label="Rating (1–5)" for="form.rating"><input id="form.rating" type="number" min="1" max="5" wire:model="form.rating" class="form-input"></x-ui.field>
                <label class="flex items-center gap-2 self-end pb-3 text-sm"><input type="checkbox" wire:model="form.possible_adverse_event" class="form-check">Possible adverse event</label>
                <x-ui.field label="What the customer said" for="form.description" class="sm:col-span-2" hint="Do not record patient details."><textarea id="form.description" wire:model="form.description" rows="3" class="form-input"></textarea></x-ui.field>
            </form>
        @endif
        <x-slot:footer><button type="button" class="btn-light" @click="open = null">Cancel</button><button type="submit" form="fb-form" class="btn-primary">Record</button></x-slot:footer>
    </x-ui.modal>
</div>

<div>
    <div class="grid gap-4 lg:grid-cols-2 xl:grid-cols-3">
        @forelse ($plans as $p)
            <x-ui.card :title="$p['name']" icon="credit-card" wire:key="p-{{ $p['id'] }}">
                <x-slot:actions>
                    @unless ($p['is_active'])<x-ui.badge status="inactive">Inactive</x-ui.badge>@endunless
                    @unless ($p['is_public'])<x-ui.badge tone="slate">Private</x-ui.badge>@endunless
                    <button type="button" class="btn-light btn-sm" wire:click="edit('{{ $p['id'] }}')">Edit</button>
                </x-slot:actions>
                <p class="font-mono text-xs text-slate-500">{{ $p['code'] }}</p>
                <p class="mt-1 text-2xl font-semibold">{{ $p['price'] !== null ? fmt()->money($p['price'], $p['currency_code'] ?? 'USD') : 'Custom' }} <span class="text-sm font-normal text-slate-500">/ {{ $p['billing_period'] }}</span></p>
                @if ($p['trial_days'])<p class="text-xs text-slate-500">{{ $p['trial_days'] }}-day trial</p>@endif
                @if ($p['description'])<p class="mt-2 text-sm text-slate-600">{{ $p['description'] }}</p>@endif
                <ul class="mt-3 space-y-1 text-sm">
                    @foreach ($p['entitlements'] as $key => $ent)
                        @if ($ent['enabled'])<li class="flex justify-between gap-2"><span>{{ $labels[$key] ?? $key }}</span><span class="text-slate-500">{{ $ent['limit'] === null ? (str_starts_with($key, 'module.') || ! isset($labels[$key]) ? '✓' : 'Unlimited') : number_format($ent['limit']) }}</span></li>@endif
                    @endforeach
                </ul>
            </x-ui.card>
        @empty
            <x-ui.card><p class="text-sm text-slate-500">No plans.</p></x-ui.card>
        @endforelse
    </div>

    <x-ui.modal wire:model="editing" :title="'Edit plan '.($editing['code'] ?? '')" size="xl">
        @if ($editing)
            <form wire:submit="save" id="plan-form" class="space-y-5">
                <div class="grid gap-4 sm:grid-cols-3">
                    <x-ui.field label="Name" for="editing.name"><input wire:model="editing.name" class="form-input"></x-ui.field>
                    <x-ui.field label="Billing period" for="editing.billing_period"><select wire:model="editing.billing_period" class="form-select"><option value="monthly">Monthly</option><option value="annual">Annual</option><option value="custom">Custom</option></select></x-ui.field>
                    <x-ui.field label="Trial days" for="editing.trial_days"><input wire:model="editing.trial_days" type="number" min="0" max="365" class="form-input"></x-ui.field>
                    <x-ui.field label="Price" for="editing.price" hint="Leave empty for a custom-priced plan"><input wire:model="editing.price" type="number" step="0.01" min="0" class="form-input"></x-ui.field>
                    <x-ui.field label="Currency" for="editing.currency_code"><input wire:model="editing.currency_code" maxlength="3" class="form-input uppercase"></x-ui.field>
                    <div class="flex items-end gap-4"><label class="form-check"><input type="checkbox" wire:model="editing.is_public"> Public</label><label class="form-check"><input type="checkbox" wire:model="editing.is_active"> Active</label></div>
                    <div class="sm:col-span-3"><x-ui.field label="Description" for="editing.description"><textarea wire:model="editing.description" rows="2" class="form-input"></textarea></x-ui.field></div>
                </div>
                <div>
                    <h3 class="mb-2 text-sm font-semibold">Entitlements</h3>
                    <div class="table-wrap rounded-lg border border-slate-200">
                        <table class="data-table">
                            <thead><tr><th>Entitlement</th><th>Included</th><th>Limit</th></tr></thead>
                            <tbody>
                                @foreach ($editing['entitlements'] as $i => $row)
                                    <tr wire:key="ent-{{ $i }}">
                                        <td>{{ $row['label'] }} <span class="font-mono text-[11px] text-slate-400">{{ $row['key'] }}</span></td>
                                        <td><input type="checkbox" wire:model="editing.entitlements.{{ $i }}.enabled"></td>
                                        <td>@if ($row['type'] === 'limit')<input wire:model="editing.entitlements.{{ $i }}.limit" type="number" min="0" class="form-input w-32 py-1" placeholder="Unlimited">@endif</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </form>
        @endif
        <x-slot:footer><button type="button" class="btn-light" @click="open = null">Cancel</button><button type="submit" form="plan-form" class="btn-primary">Save plan</button></x-slot:footer>
    </x-ui.modal>
</div>

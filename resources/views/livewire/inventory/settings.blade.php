<form wire:submit="save" class="max-w-2xl">
    <x-ui.card title="Stock rules" icon="cog-6-tooth">
        <div class="space-y-5">
            <label class="flex items-start gap-3">
                <input type="checkbox" wire:model="form.allow_negative_stock" class="form-check mt-0.5">
                <span><span class="block text-sm font-medium text-navy-900">Allow negative stock</span>
                    <span class="text-xs text-slate-500">Lets issues go ahead when the system shows too little stock. Leave off unless you reconcile counts often.</span></span>
            </label>
            <x-ui.field label="Minimum remaining shelf life when selling (days)" for="form.min_shelf_life_days" hint="Batches closer to expiry than this are not picked for sale. 0 means any unexpired batch.">
                <input id="form.min_shelf_life_days" type="number" min="0" max="730" wire:model="form.min_shelf_life_days" class="form-input w-40">
            </x-ui.field>
            @foreach (collect($form)->except(['allow_negative_stock', 'min_shelf_life_days']) as $key => $value)
                <p class="text-xs text-slate-500">{{ ucfirst(str_replace('_', ' ', $key)) }}: {{ is_bool($value) ? ($value ? 'yes' : 'no') : (is_array($value) ? json_encode($value) : $value) }}</p>
            @endforeach
        </div>
        <div class="mt-6 flex justify-end"><button class="btn-primary" wire:loading.attr="disabled">Save settings</button></div>
    </x-ui.card>
</form>

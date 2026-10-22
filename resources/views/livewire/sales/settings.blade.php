<form wire:submit="save" class="max-w-2xl">
    <x-ui.card title="Discounts and approvals" icon="cog-6-tooth">
        <div class="space-y-5">
            <x-ui.field label="Largest discount without approval (%)" for="form.max_discount_percent" hint="Orders with a bigger line discount, or over the customer's credit limit, wait for approval.">
                <input id="form.max_discount_percent" wire:model="form.max_discount_percent" inputmode="decimal" class="form-input w-32">
            </x-ui.field>
            <label class="flex items-start gap-3">
                <input type="checkbox" wire:model="form.allow_self_approval" class="form-check mt-0.5">
                <span><span class="block text-sm font-medium text-navy-900">Allow self-approval</span><span class="text-xs text-slate-500">Let the person who raised an order approve it.</span></span>
            </label>
        </div>
        <div class="mt-6 flex justify-end"><button class="btn-primary" wire:loading.attr="disabled">Save settings</button></div>
    </x-ui.card>
</form>

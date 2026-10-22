<form wire:submit="save" class="max-w-2xl">
    <x-ui.card title="Approvals and receiving" icon="cog-6-tooth">
        <div class="space-y-5">
            <x-ui.field :label="'Approval threshold ('.$currency.')'" for="form.approval_threshold" hint="Orders at or above this total need approval. Leave empty to require approval for every order.">
                <input id="form.approval_threshold" wire:model="form.approval_threshold" inputmode="decimal" class="form-input w-48">
            </x-ui.field>
            <label class="flex items-start gap-3">
                <input type="checkbox" wire:model="form.allow_self_approval" class="form-check mt-0.5">
                <span><span class="block text-sm font-medium text-navy-900">Allow self-approval</span><span class="text-xs text-slate-500">Let the person who raised an order or invoice approve it. Off keeps a second pair of eyes on every purchase.</span></span>
            </label>
            <x-ui.field label="Over-receipt tolerance (%)" for="form.over_receipt_tolerance_percent" hint="How much more than ordered may be received, 0 to 50.">
                <input id="form.over_receipt_tolerance_percent" wire:model="form.over_receipt_tolerance_percent" inputmode="decimal" class="form-input w-32">
            </x-ui.field>
        </div>
        <div class="mt-6 flex justify-end"><button class="btn-primary" wire:loading.attr="disabled">Save settings</button></div>
    </x-ui.card>
</form>

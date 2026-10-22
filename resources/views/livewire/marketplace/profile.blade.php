<form wire:submit="save" class="max-w-3xl">
    <x-ui.card title="Your marketplace profile" icon="identification">
        <div class="grid gap-4 sm:grid-cols-2">
            <label class="flex items-start gap-3 sm:col-span-2"><input type="checkbox" wire:model="form.listed" class="form-check mt-0.5"><span class="text-sm"><span class="font-medium text-navy-900">List us on the marketplace</span><span class="muted block">Verified buyers can find your listings and request to trade.</span></span></label>
            <x-ui.field label="Headline" for="form.headline" class="sm:col-span-2"><input id="form.headline" wire:model="form.headline" class="form-input" placeholder="Nationwide distributor of essential medicines"></x-ui.field>
            <x-ui.field label="About us" for="form.description" class="sm:col-span-2"><textarea id="form.description" wire:model="form.description" rows="4" class="form-input"></textarea></x-ui.field>
            <x-ui.field label="Regions served" for="form.regions_served" hint="Comma separated, e.g. Lagos, Ogun"><input id="form.regions_served" wire:model="form.regions_served" class="form-input"></x-ui.field>
            <x-ui.field label="Minimum order value" for="form.minimum_order_value"><input id="form.minimum_order_value" wire:model="form.minimum_order_value" inputmode="decimal" class="form-input"></x-ui.field>
            <div class="sm:col-span-2"><p class="form-label">Delivery options</p><div class="flex gap-5">@foreach ($options as $o)<label class="flex items-center gap-2 text-sm"><input type="checkbox" value="{{ $o }}" wire:model="form.delivery_options" class="form-check">{{ ucfirst($o) }}</label>@endforeach</div></div>
            <x-ui.field label="Contact email" for="form.contact_email"><input id="form.contact_email" wire:model="form.contact_email" class="form-input"></x-ui.field>
            <x-ui.field label="Contact phone" for="form.contact_phone"><input id="form.contact_phone" wire:model="form.contact_phone" class="form-input"></x-ui.field>
        </div>
        <div class="mt-6 flex justify-end"><button class="btn-primary" wire:loading.attr="disabled">Save profile</button></div>
    </x-ui.card>
</form>

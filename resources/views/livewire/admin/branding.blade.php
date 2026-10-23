<div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
    <x-ui.card title="Branding" icon="swatch">
        <form wire:submit="save" class="grid gap-4 sm:grid-cols-2">
            <x-ui.field label="Display name" for="brand.display_name"><input wire:model.live.debounce.300ms="brand.display_name" class="form-input"></x-ui.field>
            <x-ui.field label="Website" for="brand.website_url"><input wire:model="brand.website_url" type="url" class="form-input"></x-ui.field>
            <x-ui.field label="Logo URL" for="brand.logo_url"><input wire:model.live.debounce.500ms="brand.logo_url" type="url" class="form-input"></x-ui.field>
            <x-ui.field label="Favicon URL" for="brand.favicon_url"><input wire:model="brand.favicon_url" type="url" class="form-input"></x-ui.field>
            <x-ui.field label="Primary colour" for="brand.primary_color"><div class="flex gap-2"><input type="color" wire:model.live="brand.primary_color" class="h-10 w-12 rounded border border-slate-200"><input wire:model.live.debounce.300ms="brand.primary_color" class="form-input font-mono"></div></x-ui.field>
            <x-ui.field label="Secondary colour" for="brand.secondary_color"><div class="flex gap-2"><input type="color" wire:model.live="brand.secondary_color" class="h-10 w-12 rounded border border-slate-200"><input wire:model.live.debounce.300ms="brand.secondary_color" class="form-input font-mono"></div></x-ui.field>
            <x-ui.field label="Email sender name" for="brand.email_from_name"><input wire:model="brand.email_from_name" class="form-input"></x-ui.field>
            <x-ui.field label="Email sender address" for="brand.email_from_address"><input wire:model="brand.email_from_address" type="email" class="form-input"></x-ui.field>
            <x-ui.field label="Support email" for="brand.support_email"><input wire:model="brand.support_email" type="email" class="form-input"></x-ui.field>
            <x-ui.field label="Support phone" for="brand.support_phone"><input wire:model="brand.support_phone" class="form-input"></x-ui.field>
            <div class="flex justify-end sm:col-span-2"><button class="btn-primary">Save branding</button></div>
        </form>
    </x-ui.card>
    <x-ui.card title="Preview" icon="eye">
        <div class="overflow-hidden rounded-lg border border-slate-200">
            <div class="flex items-center gap-2 px-4 py-3 text-white" style="background: {{ preg_match('/^#[0-9a-fA-F]{6}$/', $brand['primary_color'] ?? '') ? $brand['primary_color'] : '#0f172a' }}">
                @if ($brand['logo_url'])<img src="{{ $brand['logo_url'] }}" alt="" class="h-6 w-6 rounded bg-white object-contain">@endif
                <span class="font-semibold">{{ $brand['display_name'] }}</span>
            </div>
            <div class="space-y-3 p-4 text-sm">
                <p class="text-slate-600">Buttons and highlights use the secondary colour.</p>
                <span class="inline-block rounded-md px-3 py-1.5 font-medium text-white" style="background: {{ preg_match('/^#[0-9a-fA-F]{6}$/', $brand['secondary_color'] ?? '') ? $brand['secondary_color'] : '#c8a24a' }}">Sample button</span>
                <p class="text-xs text-slate-500">Support: {{ $brand['support_email'] }}@if ($brand['support_phone']) · {{ $brand['support_phone'] }}@endif</p>
            </div>
        </div>
        <p class="mt-3 text-xs text-slate-500">An organization that sets its own branding overrides these for its users.</p>
    </x-ui.card>
</div>

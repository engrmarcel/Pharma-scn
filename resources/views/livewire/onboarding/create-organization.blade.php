<div class="mx-auto max-w-3xl space-y-6 py-4">
    @if (session('status'))
        <div role="status" class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('status') }}</div>
    @endif

    <x-ui.card title="Register your organization" icon="building-office-2">
        <p class="mb-5 text-sm text-slate-600">You become its owner. It starts on the default plan and unverified. Some features, such as trading restricted products on the marketplace, open once platform staff verify your documents.</p>
        <form wire:submit="save" class="grid gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2"><x-ui.field label="Organization name" for="org.name"><input wire:model="org.name" class="form-input" autofocus></x-ui.field></div>
            <x-ui.field label="Type" for="org.type"><select wire:model="org.type" class="form-select">@foreach ($types as $t)<option value="{{ $t->value }}">{{ $t->label() }}</option>@endforeach</select></x-ui.field>
            <x-ui.field label="Country" for="org.country_code"><select wire:model.live="org.country_code" class="form-select">@foreach ($countries as $c)<option value="{{ $c['code'] }}">{{ $c['name'] }}</option>@endforeach</select></x-ui.field>
            <x-ui.field label="Legal name" for="org.legal_name" hint="If different from the trading name"><input wire:model="org.legal_name" class="form-input"></x-ui.field>
            <x-ui.field label="Email" for="org.email"><input wire:model="org.email" type="email" class="form-input"></x-ui.field>
            <x-ui.field label="Phone" for="org.phone"><input wire:model="org.phone" class="form-input"></x-ui.field>
            <x-ui.field label="Address" for="org.address_line1"><input wire:model="org.address_line1" class="form-input"></x-ui.field>
            <x-ui.field label="City" for="org.city"><input wire:model="org.city" class="form-input"></x-ui.field>
            <x-ui.field :label="$labels['state_region'] ?? 'Region'" for="org.state_region"><input wire:model="org.state_region" class="form-input"></x-ui.field>
            <div class="flex justify-end sm:col-span-2"><button class="btn-primary">Register organization</button></div>
        </form>
    </x-ui.card>

    <x-ui.card title="Were you invited?" icon="envelope-open">
        <p class="text-sm text-slate-600">If an organization invited you, accept with the code from the email instead. <a href="{{ route('invitations.accept') }}" class="link">Accept an invitation</a></p>
    </x-ui.card>
</div>

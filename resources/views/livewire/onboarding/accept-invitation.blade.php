<div class="mx-auto max-w-xl py-4">
    <x-ui.card title="Accept an invitation" icon="envelope-open">
        <p class="mb-4 text-sm text-slate-600">Paste the code from the invitation email. It must have been sent to {{ auth()->user()->email }}.</p>
        <form wire:submit="accept" class="space-y-4">
            <x-ui.field label="Invitation code" for="invite.token"><input wire:model="token" class="form-input font-mono" autofocus autocomplete="off"></x-ui.field>
            <div class="flex items-center justify-between gap-3">
                <a href="{{ route('onboarding.organization') }}" class="link text-sm">Register a new organization instead</a>
                <button class="btn-primary">Join organization</button>
            </div>
        </form>
    </x-ui.card>
</div>

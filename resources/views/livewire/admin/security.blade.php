<div class="max-w-3xl">
    <x-ui.card title="Security policy" icon="lock-closed">
        <form wire:submit="save" class="space-y-6">
            <fieldset>
                <legend class="mb-2 text-sm font-semibold">Two-factor authentication</legend>
                <div class="space-y-2">
                    @foreach ($modes as $value => [$label, $hint])
                        <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-slate-200 p-3 has-[:checked]:border-gold-400 has-[:checked]:bg-gold-50/40">
                            <input type="radio" wire:model="policy.mfa_mode" value="{{ $value }}" class="mt-1">
                            <span><span class="block font-medium">{{ $label }}</span><span class="muted">{{ $hint }}</span></span>
                        </label>
                    @endforeach
                </div>
                <p class="mt-2 text-xs text-slate-500">To make two-factor mandatory you must first turn it on for your own account.</p>
            </fieldset>
            <label class="form-check"><input type="checkbox" wire:model="policy.email_verification_required"> Users must verify their email address before signing in</label>
            <div class="flex justify-end"><button class="btn-primary">Save policy</button></div>
        </form>
    </x-ui.card>
</div>

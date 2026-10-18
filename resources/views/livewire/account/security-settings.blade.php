<div class="max-w-2xl space-y-6">
    <h1 class="text-2xl font-semibold">Account security</h1>

    <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
        <h2 class="font-semibold">Email address</h2>
        <p class="mt-1 text-sm text-slate-600">
            {{ auth()->user()->email }} —
            @if ($emailVerified)
                <span class="font-medium text-emerald-700">verified</span>
            @else
                <span class="font-medium text-amber-700">not verified</span>
                @if ($policy['email_verification_required'])
                    <span>(verification is required to use the platform)</span>
                @endif
            @endif
        </p>
        @unless ($emailVerified)
            <button wire:click="resendVerification" class="mt-3 rounded border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50">Send verification link</button>
        @endunless
    </section>

    <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
        <h2 class="font-semibold">Two-factor authentication</h2>

        @if ($policy['mfa_mode'] === 'off')
            <p class="mt-1 text-sm text-slate-600">Two-factor authentication is not enabled on this platform.</p>
        @elseif ($mfaEnabled)
            <p class="mt-1 text-sm text-emerald-700">Enabled. You will be asked for a code each time you sign in.</p>

            @if ($recoveryCodes)
                <div class="mt-4 rounded border border-amber-200 bg-amber-50 p-3 text-sm">
                    <p class="font-medium">Recovery codes — store them somewhere safe. They will not be shown again.</p>
                    <ul class="mt-2 grid grid-cols-2 gap-1 font-mono">
                        @foreach ($recoveryCodes as $rc)
                            <li>{{ $rc }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="mt-4 grid gap-3 sm:grid-cols-2">
                <label class="block text-sm">
                    <span class="mb-1 block">Current code (or recovery code to disable)</span>
                    <input wire:model="code" autocomplete="one-time-code" class="w-full rounded border border-slate-300 px-3 py-2">
                </label>
                <label class="block text-sm">
                    <span class="mb-1 block">Password (to disable)</span>
                    <input type="password" wire:model="password" autocomplete="current-password" class="w-full rounded border border-slate-300 px-3 py-2">
                </label>
            </div>
            @error('code') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            @error('password') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            <div class="mt-3 flex gap-2">
                <button wire:click="regenerate" class="rounded border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50">New recovery codes</button>
                @unless ($mfaRequired)
                    <button wire:click="disable" wire:confirm="Turn off two-factor authentication?" class="rounded border border-red-300 px-3 py-1.5 text-sm text-red-700">Turn off</button>
                @endunless
            </div>
        @elseif ($setup)
            <p class="mt-1 text-sm text-slate-600">Scan this code with an authenticator app (Google Authenticator, Microsoft Authenticator, Authy…), then enter the 6-digit code it shows.</p>
            <div class="mt-3 w-48">{!! $setup['qr_svg'] !!}</div>
            <p class="mt-2 text-xs text-slate-500">Or enter this key manually: <span class="font-mono">{{ $setup['secret'] }}</span></p>
            <label class="mt-3 block max-w-xs text-sm">
                <span class="mb-1 block">Code from the app</span>
                <input wire:model="code" inputmode="numeric" maxlength="6" autocomplete="one-time-code" class="w-full rounded border border-slate-300 px-3 py-2 tracking-widest">
            </label>
            @error('code') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            <button wire:click="confirmSetup" class="mt-3 rounded bg-[var(--brand-primary)] px-3 py-1.5 text-sm font-medium text-white">Confirm and enable</button>
        @else
            <p class="mt-1 text-sm text-slate-600">
                Add a second step to sign-in using an authenticator app.
                @if ($mfaRequired) <span class="font-medium text-amber-700">Required for your account.</span> @endif
            </p>
            <label class="mt-3 block max-w-xs text-sm">
                <span class="mb-1 block">Confirm your password</span>
                <input type="password" wire:model="password" autocomplete="current-password" class="w-full rounded border border-slate-300 px-3 py-2">
            </label>
            @error('password') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            @error('code') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            <button wire:click="startSetup" class="mt-3 rounded bg-[var(--brand-primary)] px-3 py-1.5 text-sm font-medium text-white">Set up two-factor</button>
        @endif
    </section>
</div>

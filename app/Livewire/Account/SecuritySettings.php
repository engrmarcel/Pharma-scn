<?php

namespace App\Livewire\Account;

use App\Domain\Access\Services\SecurityPolicy;
use App\Domain\Access\Services\TwoFactorService;
use App\Support\Exceptions\BusinessRuleViolation;
use Illuminate\Support\Facades\Hash;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The signed-in user's own email verification and two-factor settings.
 * Uses the same TwoFactorService as the API.
 */
#[Layout('layouts.app')]
#[Title('Account security')]
class SecuritySettings extends Component
{
    public string $password = '';

    public string $code = '';

    public ?array $setup = null;

    /** Shown once, right after confirmation or regeneration. */
    public ?array $recoveryCodes = null;

    public function startSetup(TwoFactorService $twoFactor): void
    {
        $this->validate(['password' => ['required', 'string']]);
        if (! Hash::check($this->password, auth()->user()->password)) {
            $this->addError('password', __('auth.password'));

            return;
        }

        $this->run(fn () => $this->setup = $twoFactor->begin(auth()->user()));
        $this->reset('password');
    }

    public function confirmSetup(TwoFactorService $twoFactor): void
    {
        $this->validate(['code' => ['required', 'string', 'max:10']]);
        $this->run(function () use ($twoFactor) {
            $this->recoveryCodes = $twoFactor->confirm(auth()->user(), $this->code);
            $this->setup = null;
        });
        $this->reset('code');
    }

    public function disable(TwoFactorService $twoFactor): void
    {
        $this->validate(['password' => ['required', 'string'], 'code' => ['required', 'string', 'max:20']]);
        if (! Hash::check($this->password, auth()->user()->password)) {
            $this->addError('password', __('auth.password'));

            return;
        }

        // Accept either a TOTP code or a recovery code in the same field.
        $isTotp = (bool) preg_match('/^\d{6}$/', trim($this->code));
        $this->run(fn () => $twoFactor->disable(auth()->user(), $isTotp ? $this->code : '', $isTotp ? null : $this->code));
        $this->reset('password', 'code', 'recoveryCodes');
    }

    public function regenerate(TwoFactorService $twoFactor): void
    {
        $this->validate(['code' => ['required', 'string', 'max:10']]);
        $this->run(fn () => $this->recoveryCodes = $twoFactor->regenerateRecoveryCodes(auth()->user(), $this->code));
        $this->reset('code');
    }

    public function resendVerification(): void
    {
        if (auth()->user()->email_verified_at === null) {
            auth()->user()->sendEmailVerificationNotification();
            session()->flash('status', 'Verification link sent to '.auth()->user()->email.'.');
        }
    }

    public function render(SecurityPolicy $policy, TwoFactorService $twoFactor)
    {
        $user = auth()->user()->fresh();

        return view('livewire.account.security-settings', [
            'policy' => $policy->current(),
            'mfaEnabled' => $twoFactor->isEnabled($user),
            'mfaRequired' => $policy->mfaRequiredFor($user),
            'emailVerified' => $user->email_verified_at !== null,
        ]);
    }

    private function run(callable $action): void
    {
        try {
            $action();
        } catch (BusinessRuleViolation $e) {
            $this->addError('code', $e->getMessage());
        }
    }
}

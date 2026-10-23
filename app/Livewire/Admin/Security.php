<?php

namespace App\Livewire\Admin;

use App\Livewire\WebPage;
use Livewire\Attributes\Title;

/** Platform security policy: when two-factor is offered or required, and whether email addresses must be verified. */
#[Title('Security policy')]
class Security extends WebPage
{
    public const ROUTE = 'admin.security';

    public array $policy = [];

    public function mount(): void
    {
        $this->policy = $this->read('admin/security-settings', [], ['data' => []])['data'];
    }

    public function save(): void
    {
        $result = $this->submit('policy', 'admin/security-settings', [
            'mfa_mode' => $this->policy['mfa_mode'],
            'email_verification_required' => (bool) $this->policy['email_verification_required'],
        ], 'Security policy saved.', 'put');
        if ($result !== null) {
            $this->policy = $result['data'];
        }
    }

    public function render()
    {
        return view('livewire.admin.security', ['modes' => [
            'off' => ['Off', 'Two-factor is not offered.'],
            'optional' => ['Optional', 'Anyone can turn it on for their own account.'],
            'required_for_platform_staff' => ['Required for platform staff', 'Staff must enrol; others can opt in.'],
            'required_for_all' => ['Required for everyone', 'Every user must enrol before using the platform.'],
        ]]);
    }
}

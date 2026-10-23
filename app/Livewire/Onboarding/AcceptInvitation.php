<?php

namespace App\Livewire\Onboarding;

use App\Support\Web\ApiError;
use App\Support\Web\InternalApi;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Join an organization with the code from an invitation email (sent to the signed-in user's address). */
#[Layout('layouts.app')]
#[Title('Accept an invitation')]
class AcceptInvitation extends Component
{
    #[Url]
    public string $token = '';

    public function accept(InternalApi $api)
    {
        $this->resetErrorBag();
        try {
            $membership = $api->post('invitations/accept', ['token' => trim($this->token)], 'invite')['data'];
        } catch (ApiError $e) {
            $this->addError('invite.token', $e->getMessage());

            return null;
        }
        $organizationId = $membership['organization']['id'] ?? $membership['organization_id'] ?? null;
        if ($organizationId) {
            session()->put(config('platform.tenancy.session_key'), $organizationId);
        }
        session()->flash('status', 'You have joined '.($membership['organization']['name'] ?? 'the organization').'.');

        return $this->redirectRoute('dashboard');
    }

    public function render()
    {
        return view('livewire.onboarding.accept-invitation');
    }
}

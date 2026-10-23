<?php

namespace App\Livewire\Onboarding;

use App\Domain\Organizations\Enums\OrganizationType;
use App\Support\Web\ApiError;
use App\Support\Web\InternalApi;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Register a new organization (the signed-in user becomes its owner). It starts unverified on the
 * default plan; the owner completes the profile and submits it for verification from Organization › Profile.
 */
#[Layout('layouts.app')]
#[Title('Register your organization')]
class CreateOrganization extends Component
{
    public array $org = ['name' => '', 'type' => 'pharmacy', 'country_code' => 'NG', 'legal_name' => '', 'email' => '', 'phone' => '', 'address_line1' => '', 'city' => '', 'state_region' => ''];

    public function save(InternalApi $api)
    {
        $payload = array_filter($this->org, fn ($v) => $v !== '');
        $this->resetErrorBag();
        try {
            $created = $api->post('organizations', $payload, 'org')['data'];
        } catch (ApiError $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());

            return null;
        }
        session()->put(config('platform.tenancy.session_key'), $created['id']);
        session()->flash('status', "{$created['name']} is registered. Complete the profile and upload documents, then submit it for verification.");

        return $this->redirectRoute('organization.profile');
    }

    public function render(InternalApi $api)
    {
        try {
            $countries = $api->get('reference/countries')['data'];
        } catch (ApiError) {
            $countries = [];
        }

        return view('livewire.onboarding.create-organization', [
            'countries' => $countries,
            'types' => OrganizationType::cases(),
            'labels' => collect($countries)->firstWhere('code', $this->org['country_code'])['labels'] ?? [],
        ]);
    }
}

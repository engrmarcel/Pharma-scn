<?php

namespace App\Livewire\Organization;

use App\Domain\Platform\Models\RegulatoryAuthority;
use App\Domain\Platform\Models\RegulatoryIdentifierType;
use App\Livewire\WebPage;
use App\Support\Tenancy\TenantContext;
use App\Support\Web\ApiError;
use Livewire\Attributes\Title;
use Livewire\WithFileUploads;

/** Organization profile, country onboarding checklist, licences, documents and platform verification. */
#[Title('Profile & verification')]
class Profile extends WebPage
{
    use WithFileUploads;

    public const ROUTE = 'organization.profile';

    public ?array $form = null;

    public ?array $identifier = null;

    public string $documentCategory = 'business_registration';

    public $document = null;

    public function edit(): void
    {
        $org = $this->api()->get('organization')['data'];
        $this->form = [
            'name' => $org['name'], 'legal_name' => $org['legal_name'], 'business_registration_number' => $org['business_registration_number'],
            'tax_identification_number' => $org['tax_identification_number'], 'email' => $org['email'], 'phone' => $org['phone'],
            'website_url' => $org['website_url'], 'address_line1' => $org['address']['line1'], 'address_line2' => $org['address']['line2'],
            'city' => $org['address']['city'], 'state_region' => $org['address']['state_region'], 'postal_code' => $org['address']['postal_code'],
            'gln' => app(TenantContext::class)->organization()->gln, 'timezone' => $org['locale']['timezone'], 'locale' => $org['locale']['locale'],
        ];
    }

    public function save(): void
    {
        $data = array_map(fn ($v) => $v === '' ? null : $v, $this->form);
        if ($this->submit('form', 'organization', $data, 'Profile saved.', 'patch') !== null) {
            $this->form = null;
        }
    }

    public function addIdentifier(?string $authority = null, ?string $type = null): void
    {
        $this->identifier = ['authority_code' => $authority ?? '', 'identifier_type' => $type ?? '', 'value' => '', 'declared_status' => 'registered',
            'registration_date' => null, 'expiry_date' => null, 'holder_name' => null];
    }

    public function saveIdentifier(): void
    {
        if ($this->submit('identifier', 'organization/regulatory-identifiers', array_filter($this->identifier, fn ($v) => $v !== null && $v !== ''), 'Identifier recorded.') !== null) {
            $this->identifier = null;
        }
    }

    public function upload(): void
    {
        $this->validate(['document' => ['required', 'file', 'max:'.config('platform.attachments.max_kb')]]);
        $result = $this->attempt(fn ($api) => $api->upload('organization/documents', ['category' => $this->documentCategory], ['file' => $this->document], 'upload'), 'Document uploaded.');
        if ($result !== null) {
            $this->reset('document');
        }
    }

    public function download(string $id, string $name)
    {
        try {
            $content = $this->api()->download("organization/documents/{$id}");
        } catch (ApiError $e) {
            $this->notify($e->getMessage(), 'error');

            return null;
        }

        return response()->streamDownload(fn () => print ($content), $name);
    }

    public function submitForVerification(): void
    {
        $this->attempt(fn ($api) => $api->post('organization/verification/submit'), 'Submitted for platform verification.');
    }

    public function setRequireMfa(bool $on): void
    {
        $this->attempt(fn ($api) => $api->patch('organization/security', ['require_mfa' => $on]), $on ? 'Two-factor is now required for members.' : 'Two-factor is no longer required.');
    }

    public function render()
    {
        $org = app(TenantContext::class)->organization();

        return view('livewire.organization.profile', [
            'org' => $this->api()->get('organization')['data'],
            'requirements' => $this->read('organization/onboarding-requirements')['data'] ?? null,
            'identifiers' => $this->read('organization/regulatory-identifiers')['data'] ?? [],
            'documents' => $this->read('organization/documents')['data'] ?? [],
            'paymentMethods' => $this->read('organization/payment-methods')['data'] ?? [],
            'authorities' => RegulatoryAuthority::query()->where('is_active', true)->where('country_code', $org->country_code)->orderBy('code')->get(['code', 'name']),
            'identifierTypes' => RegulatoryIdentifierType::query()->with('authority:id,code')->where('is_active', true)
                ->whereHas('authority', fn ($q) => $q->where('country_code', $org->country_code))->get()
                ->filter(fn ($t) => array_intersect((array) $t->subject_types, ['organization', 'manufacturer']) !== [])->values(),
            'requireMfa' => (bool) data_get($org->settings, 'security.require_mfa', false),
            'canManage' => $this->can('organization.manage'),
        ]);
    }
}

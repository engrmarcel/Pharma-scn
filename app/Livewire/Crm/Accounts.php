<?php

namespace App\Livewire\Crm;

use App\Domain\Crm\Services\ContactService;
use App\Domain\Crm\Services\CustomerTimeline;
use App\Livewire\WebPage;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

/** CRM accounts: tier, call frequency and potential; professional contacts with consent; the customer timeline. */
#[Title('CRM accounts')]
class Accounts extends WebPage
{
    public const ROUTE = 'crm.accounts';

    #[Url]
    public string $search = '';

    public string $tier = '';

    public string $segment = '';

    public bool $due = false;

    #[Url(as: 'open')]
    public ?string $openId = null;

    public string $tab = 'timeline';

    public ?array $profile = null;

    public ?array $contact = null;

    protected array $filters = ['search', 'tier', 'segment', 'due'];

    public function open(string $id): void
    {
        $this->openId = $id;
        $this->tab = 'timeline';
        $this->profile = null;
        $this->contact = null;
    }

    public function editProfile(array $row): void
    {
        $this->resetErrorBag();
        $this->profile = ['tier' => $row['tier'] ?? '', 'visit_frequency_days' => (string) ($row['visit_frequency_days'] ?? ''), 'potential_value' => (string) ($row['potential_value'] ?? '')];
    }

    public function saveProfile(): void
    {
        $body = array_map(fn ($v) => $v === '' ? null : $v, $this->profile);
        if ($this->submit('profile', "crm/customers/{$this->openId}/profile", $body, 'Account profile saved.', 'patch') !== null) {
            $this->profile = null;
        }
    }

    public function newContact(): void
    {
        $this->resetErrorBag();
        $this->contact = ['id' => null, 'name' => '', 'job_title' => '', 'professional_type' => '', 'specialty' => '', 'professional_licence' => '', 'email' => '', 'phone' => '',
            'preferred_contact_method' => '', 'is_primary' => false, 'contact_consent' => 'unknown', 'consent_source' => ''];
    }

    public function saveContact(): void
    {
        $c = $this->contact;
        $data = array_filter(collect($c)->except('id')->all(), fn ($v) => $v !== '' && $v !== null);
        $data['is_primary'] = (bool) $c['is_primary'];
        if ($this->submit('contact', "crm/customers/{$this->openId}/contacts", $data, 'Contact added.') !== null) {
            $this->contact = null;
        }
    }

    public function consent(string $contactId, string $value): void
    {
        $this->attempt(fn ($api) => $api->post("crm/contacts/{$contactId}/consent", ['contact_consent' => $value, 'source' => 'Recorded on the web by '.auth()->user()->name]),
            $value === 'granted' ? 'Consent recorded.' : 'Consent withdrawn: personal channels are now blocked for this contact.');
    }

    public function anonymize(string $contactId): void
    {
        $this->attempt(fn ($api) => $api->post("crm/contacts/{$contactId}/anonymize", ['reason' => 'Erasure request handled on the web']), 'Contact anonymized.');
    }

    public function render()
    {
        $result = $this->api()->get('crm/customers', ['q' => trim($this->search), 'tier' => $this->tier, 'segment_id' => $this->segment, 'due' => $this->due ? 1 : null, 'page' => $this->page]);
        $rows = $result['data'] ?? [];
        $detail = $this->openId ? collect($rows)->firstWhere('id', $this->openId) : null;

        return view('livewire.crm.accounts', [
            'rows' => $rows,
            'meta' => $result,
            'detail' => $detail,
            'timeline' => $detail && $this->tab === 'timeline' ? ($this->read("crm/customers/{$this->openId}/timeline")['data']['entries'] ?? []) : [],
            'contacts' => $detail && $this->tab === 'contacts' ? ($this->read("crm/customers/{$this->openId}/contacts")['data'] ?? []) : [],
            'segments' => collect($this->read('crm/segments')['data'] ?? [])->pluck('name', 'id'),
            'professionalTypes' => ContactService::PROFESSIONAL_TYPES,
            'timelineTypes' => CustomerTimeline::TYPES,
            'canManage' => $this->can('crm.accounts.manage'),
        ]);
    }
}

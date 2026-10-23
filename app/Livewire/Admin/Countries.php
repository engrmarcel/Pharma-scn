<?php

namespace App\Livewire\Admin;

use App\Livewire\WebPage;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

/** Country configuration: profile, reviews, tax rates, regulators' identifier types, readiness and activation. */
#[Title('Countries')]
class Countries extends WebPage
{
    public const ROUTE = 'admin.countries';

    #[Url(as: 'country')]
    public ?string $code = null;

    public string $tab = 'readiness';

    public array $basics = [];

    public string $settingsJson = '';

    public array $reviews = [];

    public ?array $taxRate = null;

    public ?array $identifierType = null;

    public ?array $deactivating = null;

    public function open(string $code): void
    {
        $this->code = $code;
        $this->tab = 'readiness';
        $this->loadForms();
    }

    public function close(): void
    {
        $this->code = null;
    }

    private function loadForms(): void
    {
        $c = $this->read("admin/countries/{$this->code}", [], ['data' => null])['data'];
        if (! $c) {
            return;
        }
        $this->basics = collect($c)->only(['name', 'currency_code', 'timezone', 'default_locale', 'phone_code'])->all();
        $profile = $c['profile'];
        $this->reviews = collect($profile['reviews'] ?? [])->map(fn ($r) => ['status' => $r['status'] ?? 'pending', 'reference' => $r['reference'] ?? '', 'notes' => $r['notes'] ?? ''])->all();
        unset($profile['reviews']);
        $this->settingsJson = json_encode($profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    public function saveBasics(): void
    {
        $this->submit('basics', "admin/countries/{$this->code}", $this->basics, 'Country details saved.', 'patch');
    }

    public function saveSettings(): void
    {
        $settings = json_decode($this->settingsJson, true);
        if (! is_array($settings)) {
            throw ValidationException::withMessages(['settingsJson' => 'This is not valid JSON.']);
        }
        if ($this->submit('settings', "admin/countries/{$this->code}", ['settings' => $settings], 'Country profile saved.', 'patch') !== null) {
            $this->loadForms();
        }
    }

    public function saveReviews(): void
    {
        $reviews = collect($this->reviews)->map(fn ($r) => array_filter($r, fn ($v) => $v !== ''))->all();
        $this->submit('reviews', "admin/countries/{$this->code}", ['settings' => ['reviews' => $reviews]], 'Reviews saved.', 'patch');
    }

    public function startTaxRate(): void
    {
        $this->resetErrorBag();
        $this->taxRate = ['category_code' => '', 'name' => '', 'rate' => '', 'effective_from' => business_today()->toDateString(), 'source' => ''];
    }

    public function addTaxRate(): void
    {
        if ($this->submit('taxRate', "admin/countries/{$this->code}/tax-rates", $this->taxRate, 'Tax rate added; any open rate for that category now ends the day before.') !== null) {
            $this->taxRate = null;
        }
    }

    public function startIdentifierType(string $authority): void
    {
        $this->resetErrorBag();
        $this->identifierType = ['authority' => $authority, 'identifier_type' => '', 'name' => '', 'subject_types' => ['product'], 'format_pattern' => '', 'format_example' => '', 'source' => '', 'is_active' => true];
    }

    public function saveIdentifierType(): void
    {
        $t = $this->identifierType;
        $payload = collect($t)->except('authority')->map(fn ($v) => $v === '' ? null : $v)->all();
        if ($this->submit('identifierType', "admin/regulatory-authorities/{$t['authority']}/identifier-types", $payload, 'Identifier type saved.') !== null) {
            $this->identifierType = null;
        }
    }

    public function activate(): void
    {
        $this->attempt(fn () => $this->api()->post("admin/countries/{$this->code}/activate"), 'Country activated: organizations can now register here.');
    }

    public function deactivate(): void
    {
        if ($this->submit('deactivating', "admin/countries/{$this->code}/deactivate", ['reason' => $this->deactivating['reason']], 'Country deactivated.') !== null) {
            $this->deactivating = null;
        }
    }

    public function render()
    {
        $detail = $this->code ? $this->read("admin/countries/{$this->code}", [], ['data' => null])['data'] : null;
        if ($detail && $this->basics === []) {
            $this->loadForms();
        }

        return view('livewire.admin.countries', [
            'countries' => $this->read('admin/countries', [], ['data' => []])['data'],
            'detail' => $detail,
            'currencies' => array_keys(config('currencies')),
        ]);
    }
}

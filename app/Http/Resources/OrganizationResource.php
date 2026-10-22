<?php

namespace App\Http\Resources;

use App\Domain\Platform\Support\CountryProfile;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrganizationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'legal_name' => $this->legal_name,
            'type' => $this->type?->value,
            'country_code' => $this->country_code,
            'business_registration_number' => $this->business_registration_number,
            'tax_identification_number' => $this->tax_identification_number,
            'email' => $this->email,
            'phone' => $this->phone,
            'website_url' => $this->website_url,
            'address' => [
                'line1' => $this->address_line1,
                'line2' => $this->address_line2,
                'city' => $this->city,
                'state_region' => $this->state_region,
                'postal_code' => $this->postal_code,
            ],
            'status' => $this->status,
            // Platform due diligence only; not a regulatory approval.
            'platform_verification' => [
                'status' => $this->verification_status?->value,
                'verified_at' => $this->verified_at?->toIso8601String(),
            ],
            // How to show dates, times and money for this organization (clients format; the API returns raw values).
            'locale' => [
                'locale' => data_get($this->settings, 'locale.locale') ?? $this->country?->default_locale,
                'timezone' => data_get($this->settings, 'locale.timezone') ?? $this->country?->timezone,
                'currency_code' => $this->country?->currency_code,
                'currency_minor_units' => $this->country ? Money::minorUnits($this->country->currency_code) : null,
                'date_format' => $this->country ? CountryProfile::of($this->country)->get('formats.date') : null,
                'prices_include_tax' => $this->country ? (bool) CountryProfile::of($this->country)->get('tax.prices_include_tax') : null,
            ],
            'modules' => $this->whenLoaded('modules', fn () => $this->modules->where('enabled', true)->pluck('module')->values()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

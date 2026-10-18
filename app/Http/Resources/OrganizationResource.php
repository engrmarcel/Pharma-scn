<?php

namespace App\Http\Resources;

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
            'modules' => $this->whenLoaded('modules', fn () => $this->modules->where('enabled', true)->pluck('module')->values()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

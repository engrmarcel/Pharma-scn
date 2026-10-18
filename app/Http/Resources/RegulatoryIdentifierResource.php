<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RegulatoryIdentifierResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'authority' => $this->authority?->code,
            'country_code' => $this->country_code,
            'identifier_type' => $this->identifier_type,
            'value' => $this->value,
            'declared' => [
                'status' => $this->declared_status,
                'registration_date' => $this->registration_date?->toDateString(),
                'expiry_date' => $this->expiry_date?->toDateString(),
                'holder_name' => $this->holder_name,
            ],
            'platform_verification' => [
                'status' => $this->effectiveStatus()->value,
                'recorded_status' => $this->verification_status->value,
                'last_verified_at' => $this->last_verified_at?->toIso8601String(),
                'notice' => 'Platform verification record. It is not a statement or approval by the regulatory authority.',
            ],
            'verifications' => $this->whenLoaded('verifications', fn () => $this->verifications->map(fn ($v) => [
                'id' => $v->id,
                'method' => $v->method,
                'source' => $v->source,
                'result' => $v->result,
                'notes' => $v->notes,
                'verified_at' => $v->verified_at->toIso8601String(),
            ])->values()),
        ];
    }
}

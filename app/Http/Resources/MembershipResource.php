<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MembershipResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'all_locations' => $this->all_locations,
            'joined_at' => $this->joined_at?->toIso8601String(),
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ]),
            'organization' => $this->whenLoaded('organization', fn () => [
                'id' => $this->organization->id,
                'name' => $this->organization->name,
                'type' => $this->organization->type?->value,
                'status' => $this->organization->status,
            ]),
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->map(fn ($r) => ['code' => $r->code, 'name' => $r->name])->values()),
        ];
    }
}

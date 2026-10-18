<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'actor' => $this->actor ? ['id' => $this->actor->id, 'name' => $this->actor->name] : ['type' => $this->actor_type],
            'subject' => ['type' => $this->auditable_type, 'id' => $this->auditable_id],
            'old_values' => $this->old_values,
            'new_values' => $this->new_values,
            'metadata' => $this->metadata,
            'correlation_id' => $this->correlation_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

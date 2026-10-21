<?php

namespace App\Domain\Integration\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** An outbound event in the integration outbox. Append-only. */
class OutboxEvent extends Model
{
    use BelongsToOrganization;

    public const UPDATED_AT = null;

    protected $table = 'integration_outbox';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'occurred_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Outbox events are append-only.'));
        static::deleting(fn () => throw new LogicException('Outbox events are append-only.'));
    }

    /** The JSON document a subscriber receives (before connector-specific keys are added). */
    public function envelope(): array
    {
        return [
            'id' => $this->event_id,
            'type' => $this->event_type,
            'occurred_at' => $this->occurred_at->toIso8601String(),
            'organization_id' => $this->organization_id,
            'data' => $this->payload,
        ];
    }
}

<?php

namespace App\Domain\Integration\Models;

use App\Domain\Integration\Services\PayloadRedactor;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One inbound record in the durable integration inbox.
 *
 *   received     stored and acknowledged as accepted; waiting to be processed (or to be retried)
 *   processing   a worker holds it
 *   processed    applied to the business records (result holds our ids)
 *   quarantined  the data cannot be applied as sent; a person must fix the data, mapping or reference
 *   failed       unexpected errors on every attempt (dead letter); retry manually once the cause is fixed
 *   ignored      dismissed by a person, with a reason
 */
class IntegrationMessage extends Model
{
    use BelongsToOrganization;

    protected $table = 'integration_inbox';

    protected $guarded = ['id', 'organization_id'];

    protected $attributes = ['status' => 'received', 'attempts' => 0, 'schema_version' => 1];

    protected function casts(): array
    {
        return [
            'payload' => 'array', 'canonical' => 'array', 'result' => 'array', 'error_details' => 'array',
            'received_at' => 'datetime', 'next_attempt_at' => 'datetime', 'processing_started_at' => 'datetime',
            'processed_at' => 'datetime', 'resolved_at' => 'datetime',
        ];
    }

    public function connector(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnector::class, 'connector_id');
    }

    /** What the source system sees for this message (also returned for duplicates). */
    public function acknowledgement(bool $duplicate = false): array
    {
        return array_filter([
            'message_id' => $this->message_id,
            'entity_type' => $this->entity_type,
            'status' => $this->status,
            'received_at' => $this->received_at?->toIso8601String(),
            'processed_at' => $this->processed_at?->toIso8601String(),
            'result' => $this->result,
            'error' => $this->error_code ? array_filter(['code' => $this->error_code, 'message' => $this->error_message,
                'details' => app(PayloadRedactor::class)->redact($this->error_details ?? []) ?: null]) : null,
            'duplicate' => $duplicate ?: null,
        ], fn ($v) => $v !== null);
    }
}

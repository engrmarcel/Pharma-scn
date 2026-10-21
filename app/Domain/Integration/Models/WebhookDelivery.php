<?php

namespace App\Domain\Integration\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One event for one subscription.
 *   pending → delivering → delivered
 *                        → failed (retried with backoff) → … → dead (gave up; redeliver manually)
 */
class WebhookDelivery extends Model
{
    use BelongsToOrganization;

    // organization_id is set explicitly by the outbox recorder (events can be raised outside a tenant request).
    protected $guarded = ['id'];

    protected $attributes = ['status' => 'pending', 'attempts' => 0];

    protected function casts(): array
    {
        return ['next_attempt_at' => 'datetime', 'last_attempt_at' => 'datetime', 'delivered_at' => 'datetime'];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(WebhookSubscription::class, 'subscription_id');
    }

    public function outbox(): BelongsTo
    {
        return $this->belongsTo(OutboxEvent::class, 'outbox_id');
    }
}

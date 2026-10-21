<?php

namespace App\Domain\Integration\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookSubscription extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $guarded = ['id', 'organization_id'];

    protected $hidden = ['secret'];

    protected $attributes = ['status' => 'active', 'consecutive_failures' => 0];

    protected function casts(): array
    {
        return ['event_types' => 'array', 'secret' => 'encrypted', 'last_delivered_at' => 'datetime', 'last_failure_at' => 'datetime'];
    }

    public function connector(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnector::class, 'connector_id');
    }

    public function wants(string $eventType): bool
    {
        return in_array($eventType, $this->event_types ?? [], true) || in_array('*', $this->event_types ?? [], true);
    }
}

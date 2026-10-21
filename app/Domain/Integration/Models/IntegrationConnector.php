<?php

namespace App\Domain\Integration\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A registered external system (ERP, WMS, feed) and how it connects.
 * The inbound key is stored only as a hash; credentials and the signing
 * secret are encrypted at rest and never serialized.
 */
class IntegrationConnector extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $guarded = ['id', 'organization_id'];

    protected $hidden = ['credentials', 'inbound_key_hash', 'signing_secret'];

    protected $attributes = ['status' => 'draft', 'rate_limit_per_minute' => 120];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'credentials' => 'encrypted:array',
            'signing_secret' => 'encrypted',
            'inbound_key_rotated_at' => 'datetime',
            'last_message_at' => 'datetime',
            'last_processed_at' => 'datetime',
            'last_failure_at' => 'datetime',
        ];
    }

    public function mappings(): HasMany
    {
        return $this->hasMany(IntegrationFieldMapping::class, 'connector_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(IntegrationMessage::class, 'connector_id');
    }

    /** Draft and disabled connectors refuse data; a paused one stores it and processes it once resumed. */
    public function acceptsMessages(): bool
    {
        return in_array($this->status, ['active', 'paused'], true);
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings ?? [], $key, $default);
    }
}

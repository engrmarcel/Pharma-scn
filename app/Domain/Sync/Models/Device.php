<?php

namespace App\Domain\Sync\Models;

use App\Models\User;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Device extends Model
{
    use BelongsToOrganization, HasUlids;

    public const ROLES = ['field', 'pos'];

    protected $guarded = [];

    protected $attributes = ['status' => 'active', 'wipe_requested' => false];

    protected $hidden = ['organization_id'];

    protected function casts(): array
    {
        return ['wipe_requested' => 'boolean', 'last_seen_at' => 'datetime', 'last_sync_at' => 'datetime', 'revoked_at' => 'datetime', 'revoke_notified_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}

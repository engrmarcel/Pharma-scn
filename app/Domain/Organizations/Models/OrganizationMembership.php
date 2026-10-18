<?php

namespace App\Domain\Organizations\Models;

use App\Domain\Access\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Identity-level record linking a user to an organization. Deliberately not
 * tenant-scoped: tenant resolution itself has to query it.
 */
class OrganizationMembership extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected $attributes = ['status' => 'active', 'all_locations' => true];

    protected function casts(): array
    {
        return ['all_locations' => 'boolean', 'joined_at' => 'datetime'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'membership_role', 'membership_id');
    }

    public function locationScopes(): HasMany
    {
        return $this->hasMany(MembershipLocationScope::class, 'membership_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function canAccessLocation(string $type, string $id): bool
    {
        return $this->all_locations
            || $this->locationScopes()->where('location_type', $type)->where('location_id', $id)->exists();
    }
}

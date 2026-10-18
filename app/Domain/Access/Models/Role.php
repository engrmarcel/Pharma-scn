<?php

namespace App\Domain\Access\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Not covered by the tenant global scope because system roles have no
 * organization. Use assignableBy() to list roles an organization may grant.
 */
class Role extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    public function scopeAssignableBy(Builder $query, string $organizationId): Builder
    {
        return $query->where('scope', 'organization')
            ->where(fn ($q) => $q->whereNull('organization_id')->orWhere('organization_id', $organizationId));
    }

    public function scopePlatform(Builder $query): Builder
    {
        return $query->where('scope', 'platform')->whereNull('organization_id');
    }
}

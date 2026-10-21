<?php

namespace App\Domain\Marketplace\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Owned by the buyer; visible to invited suppliers once open (see scopeVisibleTo). */
class Rfq extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected $attributes = ['status' => 'draft'];

    protected function casts(): array
    {
        return ['response_deadline' => 'datetime', 'opened_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(RfqLine::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(RfqInvitation::class);
    }

    public function scopeVisibleTo(Builder $q, string $organizationId): Builder
    {
        return $q->where(fn ($w) => $w->where('buyer_organization_id', $organizationId)
            ->orWhere(fn ($s) => $s->whereIn('status', ['open', 'closed', 'awarded'])
                ->whereHas('invitations', fn ($i) => $i->where('supplier_organization_id', $organizationId))));
    }
}

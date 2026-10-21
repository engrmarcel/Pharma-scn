<?php

namespace App\Domain\Marketplace\Models;

use App\Domain\Organizations\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Shared by two organizations, so it is not tenant-scoped: queries go
 * through involving(), which limits them to the acting organization's side.
 */
class TradingRelationship extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected $attributes = ['status' => 'requested', 'payment_terms_days' => 0];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    public function scopeInvolving(Builder $query, string $organizationId): Builder
    {
        return $query->where(fn ($q) => $q->where('buyer_organization_id', $organizationId)->orWhere('supplier_organization_id', $organizationId));
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'buyer_organization_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'supplier_organization_id');
    }
}

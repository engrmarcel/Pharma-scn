<?php

namespace App\Domain\Marketplace\Models;

use App\Domain\Organizations\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketplaceQuote extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected $attributes = ['status' => 'submitted'];

    protected function casts(): array
    {
        return ['valid_until' => 'date:Y-m-d', 'decided_at' => 'datetime', 'subtotal' => 'decimal:4'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(MarketplaceQuoteLine::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'supplier_organization_id');
    }

    public function scopeInvolving(Builder $q, string $organizationId): Builder
    {
        return $q->where(fn ($w) => $w->where('buyer_organization_id', $organizationId)->orWhere('supplier_organization_id', $organizationId));
    }
}

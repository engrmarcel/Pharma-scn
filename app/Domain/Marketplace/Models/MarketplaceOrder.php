<?php

namespace App\Domain\Marketplace\Models;

use App\Domain\Organizations\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A B2B order shared by buyer and supplier; each side's ERP document is linked by id. */
class MarketplaceOrder extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected $attributes = ['status' => 'draft'];

    protected function casts(): array
    {
        return ['subtotal' => 'decimal:4', 'requested_date' => 'date:Y-m-d', 'submitted_at' => 'datetime', 'decided_at' => 'datetime'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(MarketplaceOrderLine::class);
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'buyer_organization_id');
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

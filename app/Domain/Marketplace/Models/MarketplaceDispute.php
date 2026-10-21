<?php

namespace App\Domain\Marketplace\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketplaceDispute extends Model
{
    use HasUlids;

    public const TYPES = ['damaged', 'short_delivery', 'wrong_item', 'quality', 'expiry', 'pricing', 'other'];

    public const REQUESTS = ['credit', 'replacement', 'return_and_credit', 'other'];

    protected $guarded = [];

    protected $attributes = ['status' => 'open'];

    protected function casts(): array
    {
        return ['lines' => 'array', 'resolved_at' => 'datetime', 'escalated_at' => 'datetime'];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(MarketplaceDisputeMessage::class);
    }

    public function scopeInvolving(Builder $q, string $organizationId): Builder
    {
        return $q->where(fn ($w) => $w->where('buyer_organization_id', $organizationId)->orWhere('supplier_organization_id', $organizationId));
    }
}

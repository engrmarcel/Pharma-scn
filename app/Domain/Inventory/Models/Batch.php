<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Catalogue\Models\Product;
use App\Domain\Parties\Models\Supplier;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An organization's record of a manufacturer lot. Expiry, quality and
 * recall status decide whether its stock may be issued for sale.
 */
class Batch extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $guarded = ['id', 'organization_id'];

    // Mirror the column defaults so freshly created instances are evaluated correctly.
    protected $attributes = ['quality_status' => 'released', 'recall_status' => 'none'];

    protected function casts(): array
    {
        return [
            'manufacturing_date' => 'date',
            'expiry_date' => 'date',
            'unit_cost' => 'decimal:4',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function balances(): HasMany
    {
        return $this->hasMany(InventoryBalance::class);
    }

    public function isExpired(?Carbon $on = null): bool
    {
        return $this->expiry_date !== null && $this->expiry_date->lt(($on ?? business_today())->startOfDay());
    }

    /**
     * Why this batch must not be issued for sale, or null if it may be.
     */
    public function saleBlockReason(): ?string
    {
        return match (true) {
            $this->recall_status === 'recalled' => 'recalled',
            $this->quality_status !== 'released' => $this->quality_status,
            $this->isExpired() => 'expired',
            default => null,
        };
    }
}

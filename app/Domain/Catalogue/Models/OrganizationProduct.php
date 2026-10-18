<?php

namespace App\Domain\Catalogue\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An organization's own SKU, prices and reorder settings for a canonical product.
 */
class OrganizationProduct extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return [
            'default_purchase_cost' => 'decimal:4',
            'default_selling_price' => 'decimal:4',
            'reorder_level' => 'decimal:4',
            'reorder_quantity' => 'decimal:4',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

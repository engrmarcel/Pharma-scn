<?php

namespace App\Domain\Marketplace\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class MarketplaceListing extends Model
{
    use BelongsToOrganization, HasUlids;

    public const DECLARED = ['in_stock', 'limited', 'out_of_stock', 'on_request'];

    protected $guarded = ['id', 'organization_id'];

    protected $attributes = ['status' => 'active', 'availability_mode' => 'live', 'minimum_order_quantity' => 1];

    protected function casts(): array
    {
        return ['unit_price' => 'decimal:4', 'minimum_order_quantity' => 'decimal:4', 'declared_at' => 'datetime', 'allowed_buyer_types' => 'array'];
    }
}

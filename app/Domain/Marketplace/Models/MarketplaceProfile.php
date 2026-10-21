<?php

namespace App\Domain\Marketplace\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class MarketplaceProfile extends Model
{
    use BelongsToOrganization, HasUlids;

    public const DELIVERY_OPTIONS = ['delivery', 'pickup', 'courier'];

    protected $guarded = ['id', 'organization_id'];

    protected $attributes = ['listed' => false];

    protected function casts(): array
    {
        return ['listed' => 'boolean', 'regions_served' => 'array', 'delivery_options' => 'array', 'minimum_order_value' => 'decimal:4'];
    }
}

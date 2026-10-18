<?php

namespace App\Domain\Procurement\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class SupplierPriceListItem extends Model
{
    use BelongsToOrganization;

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:4',
            'minimum_quantity' => 'decimal:4',
            'valid_from' => 'date',
            'valid_to' => 'date',
        ];
    }
}

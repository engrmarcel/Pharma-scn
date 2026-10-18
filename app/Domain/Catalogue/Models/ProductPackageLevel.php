<?php

namespace App\Domain\Catalogue\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class ProductPackageLevel extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'quantity_of_child' => 'decimal:4',
            'base_unit_quantity' => 'decimal:4',
            'is_sellable' => 'boolean',
            'is_orderable' => 'boolean',
        ];
    }
}

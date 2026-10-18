<?php

namespace App\Domain\Sales\Models;

use Illuminate\Database\Eloquent\Model;

class PriceListItem extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['unit_price' => 'decimal:4', 'minimum_quantity' => 'decimal:4', 'valid_from' => 'date', 'valid_to' => 'date'];
    }
}

<?php

namespace App\Domain\Logistics\Models;

use Illuminate\Database\Eloquent\Model;

class ShipmentItem extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'delivered_quantity' => 'decimal:4', 'returned_quantity' => 'decimal:4', 'expiry_date' => 'date:Y-m-d'];
    }
}

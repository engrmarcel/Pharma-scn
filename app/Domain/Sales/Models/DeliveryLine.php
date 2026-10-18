<?php

namespace App\Domain\Sales\Models;

use Illuminate\Database\Eloquent\Model;

class DeliveryLine extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['base_quantity' => 'decimal:4', 'unit_cost' => 'decimal:4'];
    }
}

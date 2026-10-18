<?php

namespace App\Domain\Procurement\Models;

use Illuminate\Database\Eloquent\Model;

class GoodsReceiptLine extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'base_quantity' => 'decimal:4'];
    }
}

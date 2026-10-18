<?php

namespace App\Domain\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

class StockCountLine extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['counted_quantity' => 'decimal:4', 'system_quantity' => 'decimal:4'];
    }
}

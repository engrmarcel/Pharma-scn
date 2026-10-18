<?php

namespace App\Domain\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

class StockAdjustmentLine extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['quantity_delta' => 'decimal:4'];
    }
}

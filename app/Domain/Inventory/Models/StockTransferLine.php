<?php

namespace App\Domain\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

class StockTransferLine extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'received_quantity' => 'decimal:4'];
    }
}

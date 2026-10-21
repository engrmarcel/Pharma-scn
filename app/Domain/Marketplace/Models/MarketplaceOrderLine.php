<?php

namespace App\Domain\Marketplace\Models;

use Illuminate\Database\Eloquent\Model;

class MarketplaceOrderLine extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'unit_price' => 'decimal:4', 'line_total' => 'decimal:4', 'shipped_quantity' => 'decimal:4', 'received_quantity' => 'decimal:4'];
    }
}

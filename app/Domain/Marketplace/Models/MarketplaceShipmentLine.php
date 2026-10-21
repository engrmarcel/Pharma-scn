<?php

namespace App\Domain\Marketplace\Models;

use Illuminate\Database\Eloquent\Model;

class MarketplaceShipmentLine extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'received_quantity' => 'decimal:4', 'batches' => 'array'];
    }
}

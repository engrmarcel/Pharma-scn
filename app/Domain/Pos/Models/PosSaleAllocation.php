<?php

namespace App\Domain\Pos\Models;

use Illuminate\Database\Eloquent\Model;

class PosSaleAllocation extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['base_quantity' => 'decimal:4', 'unit_cost' => 'decimal:4'];
    }
}

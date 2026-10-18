<?php

namespace App\Domain\Procurement\Models;

use Illuminate\Database\Eloquent\Model;

class SupplierReturnLine extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['base_quantity' => 'decimal:4'];
    }
}

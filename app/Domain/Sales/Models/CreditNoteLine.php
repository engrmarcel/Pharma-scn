<?php

namespace App\Domain\Sales\Models;

use Illuminate\Database\Eloquent\Model;

class CreditNoteLine extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['base_quantity' => 'decimal:4', 'line_subtotal' => 'decimal:4', 'line_tax' => 'decimal:4'];
    }
}

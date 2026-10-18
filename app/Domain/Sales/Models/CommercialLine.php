<?php

namespace App\Domain\Sales\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

abstract class CommercialLine extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4', 'base_quantity' => 'decimal:4', 'unit_price' => 'decimal:4', 'list_price' => 'decimal:4',
            'discount_percent' => 'decimal:4', 'tax_rate' => 'decimal:4', 'line_subtotal' => 'decimal:4', 'line_discount' => 'decimal:4',
            'line_tax' => 'decimal:4', 'line_total' => 'decimal:4',
        ];
    }
}

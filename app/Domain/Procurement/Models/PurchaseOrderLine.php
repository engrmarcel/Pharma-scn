<?php

namespace App\Domain\Procurement\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseOrderLine extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'base_quantity' => 'decimal:4', 'unit_price' => 'decimal:4', 'tax_rate' => 'decimal:4', 'line_subtotal' => 'decimal:4', 'line_tax' => 'decimal:4', 'received_base_quantity' => 'decimal:4', 'invoiced_base_quantity' => 'decimal:4'];
    }
}

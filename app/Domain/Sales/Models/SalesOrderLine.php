<?php

namespace App\Domain\Sales\Models;

class SalesOrderLine extends CommercialLine
{
    protected function casts(): array
    {
        return parent::casts() + ['delivered_base_quantity' => 'decimal:4', 'invoiced_base_quantity' => 'decimal:4'];
    }
}

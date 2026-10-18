<?php

namespace App\Domain\Sales\Models;

class SalesInvoiceLine extends CommercialLine
{
    protected function casts(): array
    {
        return parent::casts() + ['credited_base_quantity' => 'decimal:4'];
    }
}

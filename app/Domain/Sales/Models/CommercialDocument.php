<?php

namespace App\Domain\Sales\Models;

use App\Domain\Inventory\Models\StockDocument;
use App\Domain\Parties\Models\Customer;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Quotation, sales order and sales invoice headers share customer, warehouse and totals.
 */
abstract class CommercialDocument extends StockDocument
{
    protected function casts(): array
    {
        return parent::casts() + [
            'subtotal' => 'decimal:4', 'discount_total' => 'decimal:4', 'tax_total' => 'decimal:4', 'total' => 'decimal:4',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}

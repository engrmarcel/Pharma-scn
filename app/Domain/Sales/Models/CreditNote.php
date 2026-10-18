<?php

namespace App\Domain\Sales\Models;

use App\Domain\Inventory\Models\StockDocument;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CreditNote extends StockDocument
{
    protected function casts(): array
    {
        return parent::casts() + ['credit_date' => 'date', 'subtotal' => 'decimal:4', 'tax_total' => 'decimal:4', 'total' => 'decimal:4'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CreditNoteLine::class);
    }
}

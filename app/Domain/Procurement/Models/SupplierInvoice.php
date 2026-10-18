<?php

namespace App\Domain\Procurement\Models;

use App\Domain\Inventory\Models\StockDocument;
use App\Domain\Parties\Models\Supplier;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierInvoice extends StockDocument
{
    protected function casts(): array
    {
        return parent::casts() + ['invoice_date' => 'date', 'due_date' => 'date', 'approved_at' => 'datetime', 'subtotal' => 'decimal:4', 'tax_total' => 'decimal:4', 'total' => 'decimal:4', 'match_details' => 'array'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SupplierInvoiceLine::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}

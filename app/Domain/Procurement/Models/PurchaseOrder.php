<?php

namespace App\Domain\Procurement\Models;

use App\Domain\Inventory\Models\StockDocument;
use App\Domain\Parties\Models\Supplier;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends StockDocument
{
    protected function casts(): array
    {
        return parent::casts() + ['order_date' => 'date', 'expected_date' => 'date', 'approved_at' => 'datetime', 'sent_at' => 'datetime', 'subtotal' => 'decimal:4', 'tax_total' => 'decimal:4', 'total' => 'decimal:4'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}

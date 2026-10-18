<?php

namespace App\Domain\Procurement\Models;

use App\Domain\Inventory\Models\StockDocument;
use App\Domain\Parties\Models\Supplier;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GoodsReceipt extends StockDocument
{
    protected function casts(): array
    {
        return parent::casts() + ['received_at' => 'datetime'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(GoodsReceiptLine::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}

<?php

namespace App\Domain\Procurement\Models;

use App\Domain\Inventory\Models\StockDocument;
use App\Domain\Parties\Models\Supplier;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierReturn extends StockDocument
{
    protected function casts(): array
    {
        return parent::casts() + [];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SupplierReturnLine::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}

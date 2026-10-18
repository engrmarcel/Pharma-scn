<?php

namespace App\Domain\Inventory\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class StockAdjustment extends StockDocument
{
    public function lines(): HasMany
    {
        return $this->hasMany(StockAdjustmentLine::class);
    }
}

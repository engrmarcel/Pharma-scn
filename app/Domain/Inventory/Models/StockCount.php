<?php

namespace App\Domain\Inventory\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class StockCount extends StockDocument
{
    public function lines(): HasMany
    {
        return $this->hasMany(StockCountLine::class);
    }
}

<?php

namespace App\Domain\Sales\Models;

use App\Domain\Inventory\Models\StockDocument;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Delivery extends StockDocument
{
    protected function casts(): array
    {
        return parent::casts() + ['delivered_at' => 'datetime'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(DeliveryLine::class);
    }
}

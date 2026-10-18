<?php

namespace App\Domain\Procurement\Models;

use App\Domain\Inventory\Models\StockDocument;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseRequisition extends StockDocument
{
    protected function casts(): array
    {
        return parent::casts() + ['needed_by' => 'date', 'decided_at' => 'datetime'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseRequisitionLine::class);
    }
}

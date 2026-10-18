<?php

namespace App\Domain\Sales\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesOrder extends CommercialDocument
{
    protected function casts(): array
    {
        return parent::casts() + ['order_date' => 'date', 'requested_date' => 'date', 'approved_at' => 'datetime', 'approval_reasons' => 'array'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalesOrderLine::class);
    }
}

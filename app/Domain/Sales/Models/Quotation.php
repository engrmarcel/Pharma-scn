<?php

namespace App\Domain\Sales\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class Quotation extends CommercialDocument
{
    protected function casts(): array
    {
        return parent::casts() + ['valid_until' => 'date'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(QuotationLine::class);
    }
}

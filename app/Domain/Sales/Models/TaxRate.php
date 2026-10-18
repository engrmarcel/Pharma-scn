<?php

namespace App\Domain\Sales\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Platform-shared, effective-dated tax configuration per country and category.
 * The values are configuration maintained by platform staff, not tax advice.
 */
class TaxRate extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['rate' => 'decimal:4', 'effective_from' => 'date', 'effective_to' => 'date'];
    }
}

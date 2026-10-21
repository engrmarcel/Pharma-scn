<?php

namespace App\Domain\Marketplace\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** Append-only fee ledger: amounts are never edited; a fee can only be waived, with a reason. */
class MarketplaceFee extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected $attributes = ['status' => 'accrued'];

    protected function casts(): array
    {
        return ['basis_amount' => 'decimal:4', 'rate_percent' => 'decimal:4', 'amount' => 'decimal:4', 'waived_at' => 'datetime'];
    }
}

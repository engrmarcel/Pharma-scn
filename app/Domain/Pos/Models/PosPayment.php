<?php

namespace App\Domain\Pos\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Electronic payments stay pending until a verified provider callback or a supervisor settlement check confirms them.
 */
class PosPayment extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['confirmed_at' => 'datetime', 'amount' => 'decimal:4', 'tendered' => 'decimal:4'];
    }
}

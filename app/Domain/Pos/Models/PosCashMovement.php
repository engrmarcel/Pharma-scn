<?php

namespace App\Domain\Pos\Models;

use Illuminate\Database\Eloquent\Model;

class PosCashMovement extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'decimal:4'];
    }
}

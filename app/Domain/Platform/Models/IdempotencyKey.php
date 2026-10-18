<?php

namespace App\Domain\Platform\Models;

use Illuminate\Database\Eloquent\Model;

class IdempotencyKey extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['completed_at' => 'datetime', 'expires_at' => 'datetime'];
    }
}

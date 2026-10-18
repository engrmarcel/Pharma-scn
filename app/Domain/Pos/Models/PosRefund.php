<?php

namespace App\Domain\Pos\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class PosRefund extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['lines' => 'array', 'decided_at' => 'datetime', 'amount' => 'decimal:4'];
    }
}

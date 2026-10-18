<?php

namespace App\Domain\Inventory\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class StockReservation extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'expires_at' => 'datetime'];
    }
}

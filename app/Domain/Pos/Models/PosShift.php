<?php

namespace App\Domain\Pos\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PosShift extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['opened_at' => 'datetime', 'closed_at' => 'datetime', 'reviewed_at' => 'datetime', 'opening_float' => 'decimal:4', 'expected_cash' => 'decimal:4', 'counted_cash' => 'decimal:4', 'variance' => 'decimal:4'];
    }

    public function cashMovements(): HasMany
    {
        return $this->hasMany(PosCashMovement::class, 'pos_shift_id');
    }
}

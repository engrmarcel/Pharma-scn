<?php

namespace App\Domain\Inventory\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Authoritative stock ledger entry. Append-only: mistakes are corrected
 * with a new, opposite posting, never by editing history.
 */
class InventoryMovement extends Model
{
    use BelongsToOrganization, HasUlids;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'balance_after' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'posted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Inventory movements are immutable.'));
        static::deleting(fn () => throw new LogicException('Inventory movements are immutable.'));
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }
}

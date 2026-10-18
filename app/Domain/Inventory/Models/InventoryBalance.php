<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Organizations\Models\Warehouse;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Projection of the movement ledger per warehouse, batch and stock state.
 * Written only by InventoryLedger and ReservationService under row locks.
 */
class InventoryBalance extends Model
{
    use BelongsToOrganization;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'reserved_quantity' => 'decimal:4'];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function availableToPromise(): string
    {
        return bcsub((string) $this->quantity, (string) $this->reserved_quantity, 4);
    }
}

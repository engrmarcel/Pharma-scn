<?php

namespace App\Domain\Integration\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/** Stock reported by an external system, kept for reconciliation only. */
class InventorySnapshot extends Model
{
    use BelongsToOrganization;

    protected $table = 'integration_inventory_snapshots';

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'as_of' => 'datetime'];
    }
}

<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Organizations\Models\Warehouse;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Common base for stock adjustments, transfers and counts.
 */
abstract class StockDocument extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return [
            'posted_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}

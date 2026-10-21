<?php

namespace App\Domain\Sync\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class SyncIssue extends Model
{
    use BelongsToOrganization, HasUlids;

    public const TYPES = ['stock_shortfall', 'blocked_batch_sold', 'price_variance', 'discount_over_limit', 'prescription_control', 'payment_shortfall', 'late_offline_sale'];

    protected $guarded = [];

    protected $attributes = ['status' => 'open'];

    protected function casts(): array
    {
        return ['details' => 'array', 'quantity' => 'decimal:4', 'resolved_at' => 'datetime'];
    }
}

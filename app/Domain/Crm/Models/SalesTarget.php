<?php

namespace App\Domain\Crm\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** A sales target for a representative or a territory over a period. */
class SalesTarget extends Model
{
    use BelongsToOrganization, HasUlids;

    public const METRICS = ['invoiced_revenue', 'field_order_value', 'visits_completed', 'product_units'];

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return ['period_start' => 'date:Y-m-d', 'period_end' => 'date:Y-m-d', 'target_value' => 'decimal:4'];
    }
}

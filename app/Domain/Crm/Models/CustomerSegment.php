<?php

namespace App\Domain\Crm\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** A group of customers: chosen by hand (manual) or by criteria re-evaluated on refresh (rule). */
class CustomerSegment extends Model
{
    use BelongsToOrganization, HasUlids;

    public const TYPES = ['manual', 'rule'];

    protected $guarded = ['id', 'organization_id'];

    protected $attributes = ['status' => 'active'];

    protected function casts(): array
    {
        return ['criteria' => 'array', 'last_refreshed_at' => 'datetime'];
    }
}

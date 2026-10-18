<?php

namespace App\Domain\Organizations\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Warehouse extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $guarded = ['id', 'organization_id'];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}

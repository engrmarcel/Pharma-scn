<?php

namespace App\Domain\Marketplace\Models;

use App\Domain\Organizations\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RfqInvitation extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['status' => 'invited'];

    protected function casts(): array
    {
        return ['responded_at' => 'datetime'];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'supplier_organization_id');
    }
}

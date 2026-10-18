<?php

namespace App\Domain\FieldForce\Models;

use App\Models\User;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TerritoryMember extends Model
{
    use BelongsToOrganization;

    public const ROLES = ['representative', 'supervisor'];

    protected $guarded = ['id', 'organization_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

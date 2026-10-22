<?php

namespace App\Domain\Crm\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/** Membership of a customer in a segment; rule members are replaced on every refresh, manual ones are kept. */
class CustomerSegmentMember extends Model
{
    use BelongsToOrganization;

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return [];
    }
}

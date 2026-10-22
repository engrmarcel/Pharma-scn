<?php

namespace App\Domain\Parties\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Personal contact data for a supplier or customer. Visible only to
 * holders of the party's view permission within the same organization.
 */
class PartyContact extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean', 'consent_recorded_at' => 'datetime', 'anonymized_at' => 'datetime'];
    }
}

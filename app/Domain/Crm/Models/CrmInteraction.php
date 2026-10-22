<?php

namespace App\Domain\Crm\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** A logged contact with a customer outside a visit (call, e-mail, meeting, message, event). */
class CrmInteraction extends Model
{
    use BelongsToOrganization, HasUlids;

    public const CHANNELS = ['call', 'email', 'meeting', 'message', 'event', 'other'];

    /** Channels that reach a person directly and so require that person's consent. */
    public const PERSONAL_CHANNELS = ['call', 'email', 'message'];

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }
}

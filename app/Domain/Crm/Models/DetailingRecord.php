<?php

namespace App\Domain\Crm\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** What was detailed during a visit: product, approved messages, samples recorded and the reception. */
class DetailingRecord extends Model
{
    use BelongsToOrganization, HasUlids;

    public const RECEPTIONS = ['positive', 'neutral', 'negative', 'not_assessed'];

    protected $guarded = ['id', 'organization_id'];

    protected $attributes = ['reception' => 'not_assessed'];

    protected function casts(): array
    {
        return ['message_ids' => 'array', 'samples_quantity' => 'decimal:4', 'recorded_at' => 'datetime'];
    }
}

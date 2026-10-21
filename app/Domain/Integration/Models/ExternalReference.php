<?php

namespace App\Domain\Integration\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An external system's identifier for one of our records (spec §15.2: never assumed to equal our id). */
class ExternalReference extends Model
{
    use BelongsToOrganization;

    protected $table = 'integration_external_references';

    protected $guarded = ['id', 'organization_id'];

    public function connector(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnector::class, 'connector_id');
    }
}

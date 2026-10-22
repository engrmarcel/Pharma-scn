<?php

namespace App\Domain\Analytics\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A machine-generated recommendation, prediction or anomaly. Advisory only: it is
 * accepted or dismissed by a person, and accepting it never posts stock, money or
 * regulatory records by itself.
 */
class Recommendation extends Model
{
    use BelongsToOrganization, HasUlids;

    public const TYPES = ['reorder', 'overstock', 'expiry_risk', 'integration_anomaly', 'traceability_anomaly'];

    /** Who may see each type (in addition to analytics.view). */
    public const VIEW_PERMISSION = [
        'reorder' => 'inventory.view', 'overstock' => 'inventory.view', 'expiry_risk' => 'inventory.view',
        'integration_anomaly' => 'integrations.view', 'traceability_anomaly' => 'traceability.view',
    ];

    protected $table = 'analytics_recommendations';

    protected $guarded = ['id', 'organization_id'];

    protected $attributes = ['status' => 'open'];

    protected function casts(): array
    {
        return ['figures' => 'array', 'assumptions' => 'array', 'generated_at' => 'datetime', 'decided_at' => 'datetime'];
    }
}

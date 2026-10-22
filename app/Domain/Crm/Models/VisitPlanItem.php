<?php

namespace App\Domain\Crm\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One planned call: planned → visited (linked to the visit) | missed | cancelled. */
class VisitPlanItem extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $guarded = ['id', 'organization_id'];

    protected $attributes = ['status' => 'planned', 'sequence' => 0];

    protected function casts(): array
    {
        return ['planned_date' => 'date:Y-m-d', 'sequence' => 'integer'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(VisitPlan::class, 'visit_plan_id');
    }
}

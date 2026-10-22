<?php

namespace App\Domain\Crm\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A representative's planned calls for a period: draft → submitted → approved | rejected (by a supervisor). */
class VisitPlan extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $guarded = ['id', 'organization_id'];

    protected $attributes = ['status' => 'draft'];

    protected function casts(): array
    {
        return ['period_start' => 'date:Y-m-d', 'period_end' => 'date:Y-m-d', 'submitted_at' => 'datetime', 'decided_at' => 'datetime'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(VisitPlanItem::class)->orderBy('planned_date')->orderBy('sequence');
    }

    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'rejected'], true);
    }
}
